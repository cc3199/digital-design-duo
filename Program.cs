using System.Collections.Concurrent;
using System.Globalization;
using System.Net.Http.Headers;
using System.Net.Mail;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.FileProviders;

var builder = WebApplication.CreateBuilder(args);
builder.Services.AddHttpClient();

var databasePath = Path.Combine(builder.Environment.ContentRootPath, "data", "catalog.db");
var uploadDirectory = Path.Combine(builder.Environment.ContentRootPath, "data", "product-images", "uploads");
Directory.CreateDirectory(uploadDirectory);

var app = builder.Build();
app.UseDefaultFiles();
app.UseStaticFiles(new StaticFileOptions
{
  ServeUnknownFileTypes = true,
  DefaultContentType = "application/octet-stream"
});
app.UseStaticFiles(new StaticFileOptions
{
  FileProvider = new PhysicalFileProvider(uploadDirectory),
  RequestPath = "/product-images/uploads"
});

Product[] seedProducts =
{
  new("sunset-sticker", "Sunset State of Mind", "Sticker", 4.50m, "#f26a4f", "A glossy reminder to make room for the good stuff.", "/product-images/sunset-sticker.svg", "One size"),
  new("wildflower-sticker", "Wildflower Club", "Sticker", 4.00m, "#d4df82", "Weatherproof vinyl with a little roadside magic.", "/product-images/wildflower-sticker.svg", "One size"),
  new("mountain-sticker", "Made in the Mountains", "Sticker", 5.00m, "#5e7f75", "A bold die-cut badge for your favorite high places.", "/product-images/mountain-sticker.svg", "One size"),
  new("custom-pack", "Custom Sticker Pack", "Custom", 28.00m, "#e5b45f", "Five custom stickers designed around your world.", "/product-images/custom-pack.svg", "One size"),
  new("brand-sprint", "Tiny Brand Sprint", "Design", 325.00m, "#1e1f1c", "A focused identity direction for a business with momentum.", "/product-images/brand-sprint.svg", "One size"),
  new("thank-you-cards", "Good Things Card Set", "Paper goods", 16.00m, "#e8d8c4", "A set of eight illustrated cards for everyday thanks.", "/product-images/thank-you-cards.svg", "One size")
};
InitializeDatabase(databasePath, seedProducts);
var sessions = new ConcurrentDictionary<string, DateTimeOffset>();
var contactAttempts = new ConcurrentDictionary<string, DateTimeOffset>();
var loginAttempts = new ConcurrentDictionary<string, LoginAttempt>();

app.MapGet("/api/products", () => Results.Ok(ReadProducts(databasePath)));

app.MapPost("/api/auth/login", (LoginRequest request, HttpRequest httpRequest, HttpResponse response, IConfiguration configuration) =>
{
  var clientKey = httpRequest.HttpContext.Connection.RemoteIpAddress?.ToString() ?? "unknown";
  var now = DateTimeOffset.UtcNow;
  if (loginAttempts.TryGetValue(clientKey, out var attempt) && attempt.LockedUntil > now) return Results.Unauthorized();
  var expectedUsername = configuration["Worker:Username"];
  var expectedPassword = configuration["Worker:Password"];
  if (string.IsNullOrWhiteSpace(expectedUsername) || string.IsNullOrWhiteSpace(expectedPassword) || request.Username != expectedUsername || request.Password != expectedPassword)
  {
    var failures = attempt is null || attempt.FirstFailure.AddMinutes(5) <= now ? 1 : attempt.Failures + 1;
    loginAttempts[clientKey] = new LoginAttempt(failures, failures >= 5 ? now.AddMinutes(5) : now, now);
    return Results.Unauthorized();
  }
  loginAttempts.TryRemove(clientKey, out _);
  var token = Guid.NewGuid().ToString("N");
  sessions[token] = DateTimeOffset.UtcNow.AddHours(8);
  response.Cookies.Append("studio_worker", token, new CookieOptions { HttpOnly = true, SameSite = SameSiteMode.Strict, Secure = httpRequest.IsHttps, MaxAge = TimeSpan.FromHours(8) });
  return Results.Ok(new { name = "Studio worker" });
});

app.MapPost("/api/auth/logout", (HttpRequest request, HttpResponse response) =>
{
  if (request.Cookies.TryGetValue("studio_worker", out var token)) sessions.TryRemove(token, out _);
  response.Cookies.Delete("studio_worker");
  return Results.Ok();
});

app.MapGet("/api/auth/me", (HttpRequest request) => IsWorker(request, sessions) ? Results.Ok(new { name = "Studio worker" }) : Results.Unauthorized());

app.MapGet("/api/orders", (HttpRequest request) =>
{
  if (!IsWorker(request, sessions)) return Results.Unauthorized();
  return Results.Ok(ReadOrders(databasePath));
});

app.MapPost("/api/orders/{id}/fulfill", async (long id, HttpRequest request, IConfiguration configuration) =>
{
  if (!IsWorker(request, sessions)) return Results.Unauthorized();
  var order = ReadOrder(databasePath, id);
  if (order is null) return Results.NotFound();
  if (order.Fulfilled) return Results.Ok(order);
  if (string.IsNullOrWhiteSpace(order.CustomerEmail) || !MailAddress.TryCreate(order.CustomerEmail, out var customerAddress))
    return Results.BadRequest(new { message = "This order does not have a valid buyer email address." });

  var host = configuration["Email:SmtpHost"];
  var username = configuration["Email:Username"];
  var password = configuration["Email:Password"];
  var recipient = configuration["Email:Recipient"] ?? "digitaldesignduo02@gmail.com";
  var port = int.TryParse(configuration["Email:SmtpPort"], out var configuredPort) ? configuredPort : 587;
  if (string.IsNullOrWhiteSpace(host) || string.IsNullOrWhiteSpace(username) || string.IsNullOrWhiteSpace(password))
    return Results.Problem("Order notification email is not configured on the server.", statusCode: StatusCodes.Status503ServiceUnavailable);

  try
  {
    using var message = new MailMessage(username, customerAddress.Address)
    {
      Subject = "Your Digital Design Duo order is fulfilled",
      Body = BuildFulfillmentEmail(order),
      IsBodyHtml = false
    };
    message.ReplyToList.Add(new MailAddress(recipient));
    using var client = new SmtpClient(host, port)
    {
      EnableSsl = true,
      Credentials = new System.Net.NetworkCredential(username, password)
    };
    await client.SendMailAsync(message);
  }
  catch (SmtpException)
  {
    return Results.Problem("The fulfillment email could not be sent.", statusCode: StatusCodes.Status502BadGateway);
  }

  MarkOrderFulfilled(databasePath, id);
  return Results.Ok(ReadOrder(databasePath, id));
});

app.MapPost("/api/products", (ProductRequest request, HttpRequest httpRequest) =>
{
  if (!IsWorker(httpRequest, sessions)) return Results.Unauthorized();
  var validationError = ValidateProductRequest(request);
  if (validationError is not null) return Results.BadRequest(new { message = validationError });
  var product = new Product(Guid.NewGuid().ToString("N"), request.Name.Trim(), request.Category.Trim(), request.Price, request.Color.Trim(), request.Description.Trim(), request.ImageUrl.Trim(), NormalizeSizes(request.Sizes));
  using var connection = OpenDatabase(databasePath);
  using var command = connection.CreateCommand();
  command.CommandText = "INSERT INTO Products (Id, Name, Category, Price, Color, Description, ImageUrl, Sizes) VALUES ($id, $name, $category, $price, $color, $description, $imageUrl, $sizes)";
  AddProductParameters(command, product);
  command.ExecuteNonQuery();
  return Results.Created($"/api/products/{product.Id}", product);
});

app.MapPut("/api/products/{id}", (string id, ProductRequest request, HttpRequest httpRequest) =>
{
  if (!IsWorker(httpRequest, sessions)) return Results.Unauthorized();
  var validationError = ValidateProductRequest(request);
  if (validationError is not null) return Results.BadRequest(new { message = validationError });
  var updated = new Product(id, request.Name.Trim(), request.Category.Trim(), request.Price, request.Color.Trim(), request.Description.Trim(), request.ImageUrl.Trim(), NormalizeSizes(request.Sizes));
  using var connection = OpenDatabase(databasePath);
  using var command = connection.CreateCommand();
  command.CommandText = "UPDATE Products SET Name = $name, Category = $category, Price = $price, Color = $color, Description = $description, ImageUrl = $imageUrl, Sizes = $sizes WHERE Id = $id";
  AddProductParameters(command, updated);
  if (command.ExecuteNonQuery() == 0) return Results.NotFound();
  return Results.Ok(updated);
});

app.MapPost("/api/product-images", async (HttpRequest request) =>
{
  if (!IsWorker(request, sessions)) return Results.Unauthorized();
  var form = await request.ReadFormAsync();
  var file = form.Files.GetFile("file");
  if (file is null || file.Length == 0) return Results.BadRequest(new { message = "Choose an image to upload." });
  if (file.Length > 5 * 1024 * 1024) return Results.BadRequest(new { message = "Images must be 5 MB or smaller." });

  var allowedTypes = new[] { "image/jpeg", "image/png", "image/gif", "image/webp", "image/svg+xml" };
  if (!allowedTypes.Contains(file.ContentType, StringComparer.OrdinalIgnoreCase))
    return Results.BadRequest(new { message = "Upload a JPG, PNG, GIF, WEBP, or SVG image." });

  var extension = Path.GetExtension(file.FileName).ToLowerInvariant();
  if (string.IsNullOrWhiteSpace(extension)) extension = ".img";
  var fileName = $"{Guid.NewGuid():N}{extension}";
  await using var stream = File.Create(Path.Combine(uploadDirectory, fileName));
  await file.CopyToAsync(stream);
  return Results.Ok(new { imageUrl = $"/product-images/uploads/{fileName}" });
});

app.MapPost("/api/contact", async (ContactRequest request, IConfiguration configuration, HttpRequest httpRequest) =>
{
  if (!string.IsNullOrWhiteSpace(request.Website)) return Results.Ok();
  if (string.IsNullOrWhiteSpace(request.Name) || string.IsNullOrWhiteSpace(request.Email) || string.IsNullOrWhiteSpace(request.Message))
    return Results.BadRequest(new { message = "Complete all contact form fields." });

  var clientKey = httpRequest.HttpContext.Connection.RemoteIpAddress?.ToString() ?? "unknown";
  var now = DateTimeOffset.UtcNow;
  if (contactAttempts.TryGetValue(clientKey, out var previousAttempt) && now - previousAttempt < TimeSpan.FromSeconds(30))
    return Results.StatusCode(StatusCodes.Status429TooManyRequests);
  contactAttempts[clientKey] = now;

  if (!MailAddress.TryCreate(request.Email.Trim(), out var senderAddress))
    return Results.BadRequest(new { message = "Enter a valid email address." });

  var host = configuration["Email:SmtpHost"];
  var username = configuration["Email:Username"];
  var password = configuration["Email:Password"];
  var recipient = configuration["Email:Recipient"] ?? "digitaldesignduo02@gmail.com";
  var port = int.TryParse(configuration["Email:SmtpPort"], out var configuredPort) ? configuredPort : 587;
  if (string.IsNullOrWhiteSpace(host) || string.IsNullOrWhiteSpace(username) || string.IsNullOrWhiteSpace(password))
    return Results.Problem("Contact email is not configured on the server.", statusCode: StatusCodes.Status503ServiceUnavailable);

  try
  {
    using var message = new MailMessage(username, recipient)
    {
      Subject = $"Website contact from {request.Name.Trim()}",
      Body = $"Name: {request.Name.Trim()}\nEmail: {senderAddress.Address}\n\n{request.Message.Trim()}",
      IsBodyHtml = false
    };
    message.ReplyToList.Add(senderAddress);
    using var client = new SmtpClient(host, port)
    {
      EnableSsl = true,
      Credentials = new System.Net.NetworkCredential(username, password)
    };
    await client.SendMailAsync(message);
    return Results.Ok();
  }
  catch (SmtpException)
  {
    return Results.Problem("The message could not be sent right now.", statusCode: StatusCodes.Status502BadGateway);
  }
});

app.MapPost("/api/checkout", async (CheckoutRequest request, IHttpClientFactory clients, HttpRequest httpRequest) =>
{
  var products = ReadProducts(databasePath);
  var requestedItems = request.Items ?? [];
  if (requestedItems.Any(item => item.Quantity is < 1 or > 20)) return Results.BadRequest(new { message = "Each item quantity must be between 1 and 20." });
  var selected = requestedItems.Select(item => (Product: products.FirstOrDefault(product => product.Id == item.ProductId), item.Quantity, item.Size)).ToList();
  if (selected.Any(item => item.Product is null || !HasSize(item.Product, item.Size))) return Results.BadRequest(new { message = "One or more cart items are no longer available." });
  if (selected.Count == 0) return Results.BadRequest(new { message = "Your cart is empty." });
  var stripeKey = builder.Configuration["Stripe:SecretKey"] ?? Environment.GetEnvironmentVariable("STRIPE_SECRET_KEY");
  if (string.IsNullOrWhiteSpace(stripeKey)) return Results.Ok(new { mode = "demo", message = "Demo order ready. Add STRIPE_SECRET_KEY to enable live checkout." });
  using var client = clients.CreateClient();
  using var content = new FormUrlEncodedContent(BuildStripeFields(selected, httpRequest));
  client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", stripeKey);
  var stripeResponse = await client.PostAsync("https://api.stripe.com/v1/checkout/sessions", content);
  var body = await stripeResponse.Content.ReadAsStringAsync();
  if (!stripeResponse.IsSuccessStatusCode) return Results.Problem("Stripe could not create a checkout session.");
  using var json = JsonDocument.Parse(body);
  return Results.Ok(new { mode = "stripe", url = json.RootElement.GetProperty("url").GetString() });
});

app.MapGet("/api/checkout/verify", async (HttpRequest request, IHttpClientFactory clients, IConfiguration configuration) =>
{
  var sessionId = request.Query["session_id"].ToString();
  if (string.IsNullOrWhiteSpace(sessionId) || !sessionId.StartsWith("cs_", StringComparison.Ordinal))
    return Results.BadRequest(new { message = "A valid checkout session is required." });

  var stripeKey = configuration["Stripe:SecretKey"] ?? Environment.GetEnvironmentVariable("STRIPE_SECRET_KEY");
  if (string.IsNullOrWhiteSpace(stripeKey)) return Results.Problem("Stripe is not configured.", statusCode: StatusCodes.Status503ServiceUnavailable);

  using var client = clients.CreateClient();
  client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", stripeKey);
  var stripeResponse = await client.GetAsync($"https://api.stripe.com/v1/checkout/sessions/{Uri.EscapeDataString(sessionId)}");
  if (!stripeResponse.IsSuccessStatusCode) return Results.BadRequest(new { message = "The checkout session could not be verified." });

  using var json = JsonDocument.Parse(await stripeResponse.Content.ReadAsStringAsync());
  var paymentStatus = json.RootElement.TryGetProperty("payment_status", out var status) ? status.GetString() : null;
  return Results.Ok(new { paid = paymentStatus == "paid", status = paymentStatus });
});

app.MapPost("/api/webhooks/stripe", async (HttpRequest request, IConfiguration configuration, IHttpClientFactory clients) =>
{
  var webhookSecret = configuration["Stripe:WebhookSecret"] ?? Environment.GetEnvironmentVariable("STRIPE_WEBHOOK_SECRET");
  if (string.IsNullOrWhiteSpace(webhookSecret)) return Results.Problem("Stripe webhook is not configured.", statusCode: StatusCodes.Status503ServiceUnavailable);

  var signature = request.Headers["Stripe-Signature"].ToString();
  using var reader = new StreamReader(request.Body);
  var payload = await reader.ReadToEndAsync();
  if (!IsValidStripeSignature(payload, signature, webhookSecret)) return Results.Unauthorized();

  using var json = JsonDocument.Parse(payload);
  var root = json.RootElement;
  var eventType = root.GetProperty("type").GetString();
  if (eventType is not ("checkout.session.completed" or "checkout.session.async_payment_succeeded"))
    return Results.Ok(new { received = true });

  var eventId = root.GetProperty("id").GetString() ?? "";
  var session = root.GetProperty("data").GetProperty("object");
  var sessionId = session.GetProperty("id").GetString() ?? "";
  var paymentStatus = session.TryGetProperty("payment_status", out var paymentStatusElement) ? paymentStatusElement.GetString() ?? "unknown" : "unknown";
  var amountTotal = session.TryGetProperty("amount_total", out var amountElement) && amountElement.TryGetInt64(out var amount) ? amount : 0;
  var currency = session.TryGetProperty("currency", out var currencyElement) ? currencyElement.GetString() ?? "usd" : "usd";
  var customerEmail = session.TryGetProperty("customer_details", out var customerDetails) && customerDetails.TryGetProperty("email", out var emailElement) ? emailElement.GetString() : null;
  var shippingAddress = ReadShippingAddress(session);
  var stripeKey = configuration["Stripe:SecretKey"] ?? Environment.GetEnvironmentVariable("STRIPE_SECRET_KEY");
  if (string.IsNullOrWhiteSpace(stripeKey)) return Results.Problem("Stripe is not configured.", statusCode: StatusCodes.Status503ServiceUnavailable);
  using var stripeClient = clients.CreateClient();
  stripeClient.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", stripeKey);
  var lineItemsResponse = await stripeClient.GetAsync($"https://api.stripe.com/v1/checkout/sessions/{Uri.EscapeDataString(sessionId)}/line_items");
  if (!lineItemsResponse.IsSuccessStatusCode) return Results.Problem("The order items could not be retrieved from Stripe.", statusCode: StatusCodes.Status502BadGateway);
  using var lineItemsJson = JsonDocument.Parse(await lineItemsResponse.Content.ReadAsStringAsync());
  var orderItems = ReadStripeOrderItems(lineItemsJson.RootElement);
  SaveOrder(databasePath, eventId, sessionId, customerEmail, shippingAddress, amountTotal, currency, paymentStatus, JsonSerializer.Serialize(orderItems));
  return Results.Ok(new { received = true });
});

app.MapFallbackToFile("index.html");
app.Run();

static bool IsWorker(HttpRequest request, ConcurrentDictionary<string, DateTimeOffset> sessions) => request.Cookies.TryGetValue("studio_worker", out var token) && sessions.TryGetValue(token, out var expiry) && expiry > DateTimeOffset.UtcNow;

static bool IsValidStripeSignature(string payload, string signatureHeader, string secret)
{
  var timestamp = 0L;
  var signatures = new List<string>();
  foreach (var part in signatureHeader.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
  {
    var pieces = part.Split('=', 2);
    if (pieces.Length != 2) continue;
    if (pieces[0] == "t") long.TryParse(pieces[1], NumberStyles.Integer, CultureInfo.InvariantCulture, out timestamp);
    if (pieces[0] == "v1") signatures.Add(pieces[1]);
  }
  if (timestamp == 0 || Math.Abs(DateTimeOffset.UtcNow.ToUnixTimeSeconds() - timestamp) > 300 || signatures.Count == 0) return false;

  using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret));
  var expected = Convert.ToHexString(hmac.ComputeHash(Encoding.UTF8.GetBytes($"{timestamp}.{payload}"))).ToLowerInvariant();
  return signatures.Any(signature => CryptographicOperations.FixedTimeEquals(Encoding.UTF8.GetBytes(expected), Encoding.UTF8.GetBytes(signature)));
}

static string BuildFulfillmentEmail(Order order)
{
  var items = order.Items.Count == 0 ? "Order items are available in your receipt." : string.Join("\n", order.Items.Select(item => $"- {item.Quantity} x {item.Description}"));
  return $"Good news! Your Digital Design Duo order has been fulfilled and is on its way.\n\nItems:\n{items}\n\nThank you for supporting our small studio!";
}

static string ReadShippingAddress(JsonElement session)
{
  if (!session.TryGetProperty("shipping_details", out var shipping) || !shipping.TryGetProperty("address", out var address)) return "";
  var name = shipping.TryGetProperty("name", out var nameElement) ? nameElement.GetString() : null;
  var parts = new[]
  {
    name,
    address.TryGetProperty("line1", out var line1) ? line1.GetString() : null,
    address.TryGetProperty("line2", out var line2) ? line2.GetString() : null,
    address.TryGetProperty("city", out var city) ? city.GetString() : null,
    address.TryGetProperty("state", out var state) ? state.GetString() : null,
    address.TryGetProperty("postal_code", out var postalCode) ? postalCode.GetString() : null,
    address.TryGetProperty("country", out var country) ? country.GetString() : null
  };
  return string.Join(", ", parts.Where(part => !string.IsNullOrWhiteSpace(part)));
}

static void SaveOrder(string databasePath, string eventId, string sessionId, string? customerEmail, string shippingAddress, long amountTotal, string currency, string paymentStatus, string itemsJson)
{
  using var connection = OpenDatabase(databasePath);
  using var command = connection.CreateCommand();
  command.CommandText = "INSERT OR IGNORE INTO Orders (StripeEventId, StripeSessionId, CustomerEmail, ShippingAddress, AmountTotal, Currency, PaymentStatus, CreatedAt, ItemsJson) VALUES ($eventId, $sessionId, $email, $shippingAddress, $amount, $currency, $status, $createdAt, $items)";
  command.Parameters.AddWithValue("$eventId", eventId);
  command.Parameters.AddWithValue("$sessionId", sessionId);
  command.Parameters.AddWithValue("$email", customerEmail ?? "");
  command.Parameters.AddWithValue("$shippingAddress", shippingAddress);
  command.Parameters.AddWithValue("$amount", amountTotal);
  command.Parameters.AddWithValue("$currency", currency);
  command.Parameters.AddWithValue("$status", paymentStatus);
  command.Parameters.AddWithValue("$createdAt", DateTimeOffset.UtcNow.ToString("O", CultureInfo.InvariantCulture));
  command.Parameters.AddWithValue("$items", itemsJson);
  command.ExecuteNonQuery();
}

static List<Order> ReadOrders(string databasePath)
{
  using var connection = OpenDatabase(databasePath);
  using var command = connection.CreateCommand();
  command.CommandText = "SELECT Id, StripeEventId, StripeSessionId, CustomerEmail, ShippingAddress, AmountTotal, Currency, PaymentStatus, CreatedAt, Fulfilled, FulfilledAt, ItemsJson FROM Orders ORDER BY Id DESC";
  using var reader = command.ExecuteReader();
  var orders = new List<Order>();
  while (reader.Read()) orders.Add(ParseOrder(reader));
  return orders;
}

static Order? ReadOrder(string databasePath, long id)
{
  using var connection = OpenDatabase(databasePath);
  using var command = connection.CreateCommand();
  command.CommandText = "SELECT Id, StripeEventId, StripeSessionId, CustomerEmail, ShippingAddress, AmountTotal, Currency, PaymentStatus, CreatedAt, Fulfilled, FulfilledAt, ItemsJson FROM Orders WHERE Id = $id";
  command.Parameters.AddWithValue("$id", id);
  using var reader = command.ExecuteReader();
  return reader.Read() ? ParseOrder(reader) : null;
}

static Order ParseOrder(SqliteDataReader reader)
{
  var itemsJson = reader.IsDBNull(11) ? "[]" : reader.GetString(11);
  var items = JsonSerializer.Deserialize<List<OrderItem>>(itemsJson) ?? [];
  return new Order(reader.GetInt64(0), reader.GetString(1), reader.GetString(2), reader.IsDBNull(3) ? "" : reader.GetString(3), reader.IsDBNull(4) ? "" : reader.GetString(4), reader.GetInt64(5), reader.GetString(6), reader.GetString(7), reader.GetString(8), reader.GetInt64(9) == 1, reader.IsDBNull(10) ? null : reader.GetString(10), items);
}

static List<OrderItem> ReadStripeOrderItems(JsonElement root)
{
  if (!root.TryGetProperty("data", out var data) || data.ValueKind != JsonValueKind.Array) return [];
  return data.EnumerateArray().Select(item =>
  {
    var description = item.TryGetProperty("description", out var descriptionElement) ? descriptionElement.GetString() ?? "Item" : "Item";
    var quantity = item.TryGetProperty("quantity", out var quantityElement) && quantityElement.TryGetInt32(out var parsedQuantity) ? parsedQuantity : 1;
    var amount = item.TryGetProperty("amount_total", out var amountElement) && amountElement.TryGetInt64(out var parsedAmount) ? parsedAmount : 0;
    return new OrderItem(description, quantity, amount);
  }).ToList();
}

static void MarkOrderFulfilled(string databasePath, long id)
{
  using var connection = OpenDatabase(databasePath);
  using var command = connection.CreateCommand();
  command.CommandText = "UPDATE Orders SET Fulfilled = 1, FulfilledAt = $fulfilledAt, NotificationSent = 1 WHERE Id = $id";
  command.Parameters.AddWithValue("$id", id);
  command.Parameters.AddWithValue("$fulfilledAt", DateTimeOffset.UtcNow.ToString("O", CultureInfo.InvariantCulture));
  command.ExecuteNonQuery();
}

static Dictionary<string, string> BuildStripeFields(IEnumerable<(Product? Product, int Quantity, string? Size)> selected, HttpRequest request)
{
  var fields = new Dictionary<string, string> { ["mode"] = "payment", ["success_url"] = $"{request.Scheme}://{request.Host}/?checkout=success&session_id={{CHECKOUT_SESSION_ID}}", ["cancel_url"] = $"{request.Scheme}://{request.Host}/?checkout=cancelled" };
  var index = 0;
  foreach (var item in selected)
  {
    var product = item.Product!;
    fields[$"line_items[{index}][price_data][currency]"] = "usd";
    fields[$"line_items[{index}][price_data][product_data][name]"] = string.IsNullOrWhiteSpace(item.Size) || item.Size == "One size" ? product.Name : $"{product.Name} - {item.Size}";
    fields[$"line_items[{index}][price_data][unit_amount]"] = ((int)(PriceForSize(product, item.Size) * 100)).ToString();
    fields[$"line_items[{index}][quantity]"] = item.Quantity.ToString();
    index++;
  }
  var shipping = ShippingFor(selected);
  if (shipping > 0 || selected.Any(item => RequiresShipping(item.Product)))
  {
    fields["shipping_address_collection[allowed_countries][0]"] = "US";
    if (shipping > 0)
    {
      fields[$"line_items[{index}][price_data][currency]"] = "usd";
      fields[$"line_items[{index}][price_data][product_data][name]"] = "Shipping";
      fields[$"line_items[{index}][price_data][unit_amount]"] = ((int)(shipping * 100)).ToString();
      fields[$"line_items[{index}][quantity]"] = "1";
    }
  }
  return fields;
}

static bool RequiresShipping(Product? product) => product is not null && product.Category is "Sticker" or "Custom" or "Paper goods" or "Handmade Craft";

static decimal ShippingFor(IEnumerable<(Product? Product, int Quantity, string? Size)> selected)
{
  if (selected.Any(item => item.Product?.Category == "Handmade Craft")) return 9m;
  if (selected.Any(item => item.Product?.Category == "Paper goods")) return 5m;
  return 0m;
}

static bool HasSize(Product product, string? size)
{
  var requestedSize = string.IsNullOrWhiteSpace(size) ? "One size" : size.Trim();
  return product.Sizes.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
    .Any(option => option.Split(':', 2)[0].Trim() == requestedSize);
}

static void InitializeDatabase(string databasePath, IEnumerable<Product> seedProducts)
{
  Directory.CreateDirectory(Path.GetDirectoryName(databasePath)!);
  using var connection = OpenDatabase(databasePath);
  using var createCommand = connection.CreateCommand();
  createCommand.CommandText = "CREATE TABLE IF NOT EXISTS Products (Id TEXT PRIMARY KEY, Name TEXT NOT NULL, Category TEXT NOT NULL, Price REAL NOT NULL, Color TEXT NOT NULL, Description TEXT NOT NULL, ImageUrl TEXT NOT NULL, Sizes TEXT NOT NULL DEFAULT 'One size')";
  createCommand.ExecuteNonQuery();
  using var ordersCommand = connection.CreateCommand();
  ordersCommand.CommandText = "CREATE TABLE IF NOT EXISTS Orders (Id INTEGER PRIMARY KEY AUTOINCREMENT, StripeEventId TEXT NOT NULL UNIQUE, StripeSessionId TEXT NOT NULL UNIQUE, CustomerEmail TEXT NOT NULL, ShippingAddress TEXT NOT NULL DEFAULT '', AmountTotal INTEGER NOT NULL, Currency TEXT NOT NULL, PaymentStatus TEXT NOT NULL, CreatedAt TEXT NOT NULL, Fulfilled INTEGER NOT NULL DEFAULT 0, FulfilledAt TEXT, NotificationSent INTEGER NOT NULL DEFAULT 0, ItemsJson TEXT NOT NULL DEFAULT '[]')";
  ordersCommand.ExecuteNonQuery();
  AddOrderColumnIfMissing(connection, "Fulfilled", "INTEGER NOT NULL DEFAULT 0");
  AddOrderColumnIfMissing(connection, "FulfilledAt", "TEXT");
  AddOrderColumnIfMissing(connection, "NotificationSent", "INTEGER NOT NULL DEFAULT 0");
  AddOrderColumnIfMissing(connection, "ItemsJson", "TEXT NOT NULL DEFAULT '[]'");
  AddOrderColumnIfMissing(connection, "ShippingAddress", "TEXT NOT NULL DEFAULT ''");
  using var columnCommand = connection.CreateCommand();
  columnCommand.CommandText = "PRAGMA table_info(Products)";
  using var columns = columnCommand.ExecuteReader();
  var hasSizes = false;
  while (columns.Read()) hasSizes |= columns.GetString(1) == "Sizes";
  if (!hasSizes)
  {
    using var alterCommand = connection.CreateCommand();
    alterCommand.CommandText = "ALTER TABLE Products ADD COLUMN Sizes TEXT NOT NULL DEFAULT 'One size'";
    alterCommand.ExecuteNonQuery();
  }

  foreach (var product in seedProducts)
  {
    using var seedCommand = connection.CreateCommand();
    seedCommand.CommandText = "INSERT OR IGNORE INTO Products (Id, Name, Category, Price, Color, Description, ImageUrl, Sizes) VALUES ($id, $name, $category, $price, $color, $description, $imageUrl, $sizes)";
    AddProductParameters(seedCommand, product);
    seedCommand.ExecuteNonQuery();
  }
}

static void AddOrderColumnIfMissing(SqliteConnection connection, string columnName, string definition)
{
  var exists = false;
  using (var columnsCommand = connection.CreateCommand())
  {
    columnsCommand.CommandText = "PRAGMA table_info(Orders)";
    using var columns = columnsCommand.ExecuteReader();
    while (columns.Read()) exists |= columns.GetString(1) == columnName;
  }
  if (exists) return;
  using var alterCommand = connection.CreateCommand();
  alterCommand.CommandText = $"ALTER TABLE Orders ADD COLUMN {columnName} {definition}";
  alterCommand.ExecuteNonQuery();
}

static List<Product> ReadProducts(string databasePath)
{
  using var connection = OpenDatabase(databasePath);
  using var command = connection.CreateCommand();
  command.CommandText = "SELECT Id, Name, Category, Price, Color, Description, ImageUrl, Sizes FROM Products ORDER BY rowid";
  using var reader = command.ExecuteReader();
  var products = new List<Product>();
  while (reader.Read())
  {
    products.Add(new Product(reader.GetString(0), reader.GetString(1), reader.GetString(2), reader.GetDecimal(3), reader.GetString(4), reader.GetString(5), reader.GetString(6), reader.GetString(7)));
  }
  return products;
}

static SqliteConnection OpenDatabase(string databasePath)
{
  var connection = new SqliteConnection($"Data Source={databasePath}");
  connection.Open();
  return connection;
}

static void AddProductParameters(SqliteCommand command, Product product)
{
  command.Parameters.AddWithValue("$id", product.Id);
  command.Parameters.AddWithValue("$name", product.Name);
  command.Parameters.AddWithValue("$category", product.Category);
  command.Parameters.AddWithValue("$price", product.Price);
  command.Parameters.AddWithValue("$color", product.Color);
  command.Parameters.AddWithValue("$description", product.Description);
  command.Parameters.AddWithValue("$imageUrl", product.ImageUrl);
  command.Parameters.AddWithValue("$sizes", product.Sizes);
}

static string NormalizeSizes(string? sizes) => string.Join(", ", (sizes ?? "").Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries).DefaultIfEmpty("One size"));

static string? ValidateProductRequest(ProductRequest request)
{
  var name = request.Name?.Trim() ?? "";
  var category = request.Category?.Trim() ?? "";
  var color = request.Color?.Trim() ?? "";
  var description = request.Description?.Trim() ?? "";
  var imageUrl = request.ImageUrl?.Trim() ?? "";
  var validCategories = new[] { "Sticker", "Custom", "Design", "Paper goods", "Handmade Craft", "Digital File" };

  if (name.Length is < 1 or > 120) return "Product names must be 1 to 120 characters.";
  if (!validCategories.Contains(category, StringComparer.Ordinal)) return "Choose a valid product category.";
  if (request.Price < 0 || request.Price > 100000) return "Price must be between $0 and $100,000.";
  if (description.Length is < 1 or > 2000) return "Descriptions must be 1 to 2,000 characters.";
  if (!Regex.IsMatch(color, "^#[0-9a-fA-F]{6}$")) return "Choose a valid accent color.";
  if (!imageUrl.StartsWith("/product-images/", StringComparison.Ordinal) || imageUrl.Contains("..", StringComparison.Ordinal)) return "Choose an uploaded product image.";
  if (request.Sizes?.Length > 500) return "Size options are too long.";
  foreach (var option in NormalizeSizes(request.Sizes).Split(','))
  {
    var parts = option.Split(':', 2, StringSplitOptions.TrimEntries);
    if (parts[0].Length is < 1 or > 50) return "Each size name must be 1 to 50 characters.";
    if (parts.Length == 2 && (!decimal.TryParse(parts[1], out var sizePrice) || sizePrice < 0 || sizePrice > 100000)) return "Each size price must be a valid amount between $0 and $100,000.";
  }
  return null;
}

static decimal PriceForSize(Product product, string? size)
{
  var option = product.Sizes.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries).FirstOrDefault(entry => entry.Split(':')[0].Trim() == (size ?? "One size"));
  return option is not null && decimal.TryParse(option.Split(':').ElementAtOrDefault(1)?.Trim(), out var price) ? price : product.Price;
}

record Product(string Id, string Name, string Category, decimal Price, string Color, string Description, string ImageUrl, string Sizes);
record ProductRequest(string Name, string Category, decimal Price, string Color, string Description, string ImageUrl, string? Sizes);
record LoginRequest(string Username, string Password);
record LoginAttempt(int Failures, DateTimeOffset LockedUntil, DateTimeOffset FirstFailure);
record CartItem(string ProductId, int Quantity, string? Size = null);
record CheckoutRequest(List<CartItem> Items);
record ContactRequest(string Name, string Email, string Message, string? Website);
record Order(long Id, string StripeEventId, string StripeSessionId, string CustomerEmail, string ShippingAddress, long AmountTotal, string Currency, string PaymentStatus, string CreatedAt, bool Fulfilled, string? FulfilledAt, List<OrderItem> Items);
record OrderItem(string Description, int Quantity, long AmountTotal);