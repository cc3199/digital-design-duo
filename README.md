# Digital Design Duo

## Stripe checkout

1. Create or sign in to a Stripe account at https://dashboard.stripe.com.
2. For local development, turn on **Test mode** and copy the secret key that starts with `sk_test_`.
3. From the `Digital Design Duo` project folder, store the test key in .NET user secrets:

```powershell
dotnet user-secrets set "Stripe:SecretKey" "sk_test_your_key_here"
```

4. Start the application:

```powershell
$env:ASPNETCORE_ENVIRONMENT = "Development"
dotnet run --urls http://localhost:5000
```

Add an item to the bag and choose **Checkout**. Stripe should open its hosted test checkout page. Use Stripe's test card `4242 4242 4242 4242`, any future expiration date, and any three-digit CVC.

The key is stored outside the project and must never be committed. For deployment, set the `Stripe__SecretKey` environment variable instead of putting the key in source control. Use a live `sk_live_` key only in a protected production environment.

Stripe Checkout is created by the server in `Program.cs`. The browser never receives the Stripe secret key. After payment, Stripe redirects to the site with a Checkout session ID, and `/api/checkout/verify` asks Stripe whether that session is paid before the cart is cleared.

The signed Stripe webhook is `POST /api/webhooks/stripe`. It handles `checkout.session.completed` and `checkout.session.async_payment_succeeded`, verifies the `Stripe-Signature` header, retrieves the Checkout line items and shipping address from Stripe, and records each event in the `Orders` table in `data/catalog.db`. Orders include the buyer email, shipping address, total, payment status, and purchased item descriptions, quantities, and amounts. Stripe event IDs and session IDs are unique, so retries do not create duplicate order rows. The browser verification remains useful for the shopper experience, but fulfillment should be based on the webhook, not solely on a browser redirect.

### Configure the webhook locally

Install the [Stripe CLI](https://docs.stripe.com/stripe-cli), sign in, and forward test events to the local endpoint:

```powershell
stripe login
stripe listen --forward-to localhost:5000/api/webhooks/stripe
```

The CLI prints a temporary signing secret beginning with `whsec_`. In a second terminal, store that secret as a user secret:

```powershell
dotnet user-secrets set "Stripe:WebhookSecret" "whsec_your_test_webhook_secret"
```

Restart the backend after changing the secret. To send a test event through the local webhook:

```powershell
stripe trigger checkout.session.completed
```

The signing secret printed by `stripe listen` is for local forwarding. It is different from the signing secret for a webhook endpoint configured in the Stripe Dashboard.

### Update Stripe CLI
npm i -g @stripe/cli@latest

### Switch to live checkout

Do this only after the site is deployed behind HTTPS and the live domain is working:

1. In the Stripe Dashboard, switch off **Test mode** and open **Developers > API keys**.
2. Copy the live secret key beginning with `sk_live_`.
3. Configure it in the production hosting environment as `Stripe__SecretKey`.
4. In **Developers > Webhooks**, create an endpoint at `https://digitaldesignduo.com/api/webhooks/stripe`.
5. Select these events: `checkout.session.completed` and `checkout.session.async_payment_succeeded`.
6. Copy the endpoint signing secret beginning with `whsec_` and configure it as `Stripe__WebhookSecret`.
7. Restart or redeploy the backend and complete one small live transaction to verify the full flow.

Never use a test webhook secret with a live key or live webhook endpoint. Confirm that the live webhook response is `2xx` in the Stripe Dashboard and that a row appears in the `Orders` table.

When changing Stripe behavior, check these areas:

- `Program.cs`, `/api/checkout`: validates cart items and creates the Stripe session.
- `Program.cs`, `BuildStripeFields()`: controls line items, currency, success URL, cancellation URL, and shipping address collection.
- `Program.cs`, `/api/checkout/verify`: verifies the returned Checkout session.
- `ClientApp/src/app/store.service.ts`: sends the cart and displays the client-side total.
- `ClientApp/src/app/app.ts`: handles cancellation, payment verification, and cart clearing.

Use separate test and live Stripe keys. Test cards only work with `sk_test_` credentials. Before going live, configure both `Stripe__SecretKey` and `Stripe__WebhookSecret` in the hosting provider, use HTTPS, and never put a Stripe key or webhook secret in Angular source code or committed configuration.

Stripe documentation to use later:
Stripe Checkout Sessions

Accept a payment with Checkout
Checkout Sessions API
Checkout line items
Collect customer addresses
Shipping rates
Fulfill orders with webhooks
Stripe webhook signing and verification
Idempotent requests
Test mode and test cards

## Shipping costs

The checkout uses these shipping rules:

| Product category | Shipping cost |
| --- | ---: |
| Sticker | Free |
| Custom | Free |
| Digital File | Free; no shipping address |
| Design | Free; no shipping address |
| Paper goods | $5 |
| Handmade Craft | $9 |

Shipping is calculated on the server before the Stripe Checkout session is created. The cart also displays the estimated shipping amount to the customer.

If shipping prices change, update both locations so the cart display and Stripe charge remain consistent:

- `Program.cs`, in the `ShippingFor()` method. This controls the actual amount sent to Stripe.
- `ClientApp/src/app/store.service.ts`, in the `shippingTotal()` method. This controls the amount displayed in the cart.

After changing shipping costs, rebuild the Angular client and restart the backend:

```powershell
cd ClientApp
npm run build
cd ..
$env:ASPNETCORE_ENVIRONMENT = "Development"
dotnet run --urls http://localhost:5000
```

Keep the amounts identical in both methods. Physical products collect a U.S. shipping address during Stripe Checkout; digital files and design services do not.

## Product categories

Products are stored in SQLite and can normally be added or edited through the worker portal without changing code. Product categories are plain strings, so spelling and capitalization must match exactly.

To add a new category, update every relevant location:

1. Add the exact category string to `validCategories` in `Program.cs` so the API accepts it.
2. Add an `<option>` for the category in both product forms in `ClientApp/src/app/workers.html`.
3. Add a filter button in `ClientApp/src/app/stickers.html` if customers should be able to filter by it.
4. Update `ClientApp/src/app/handmade.component.ts` or another category-specific component if the category needs its own page.
5. Decide whether it is physical or digital. If it is physical, add it to `RequiresShipping()` in `Program.cs` and assign its shipping behavior in `ShippingFor()`. Also update `shippingTotal()` in `ClientApp/src/app/store.service.ts` so the cart estimate matches Stripe.
6. Update the shipping table in this README and test a checkout containing the new category.

If the category needs a new public page, add its component and route in `ClientApp/src/app/app.routes.ts`, add navigation links in `ClientApp/src/app/app-shell.html`, and add the public URL to `ClientApp/public/sitemap.xml`.

### Product fields

The worker portal accepts these product fields:

- **Name:** 1 to 120 characters.
- **Category:** one of the exact values accepted by `validCategories`.
- **Price:** from `$0` to `$100,000`.
- **Accent color:** six-digit hexadecimal color such as `#f26a4f`.
- **Description:** 1 to 2,000 characters.
- **Image:** upload through the worker portal; uploaded files are stored outside generated Angular output.
- **Sizes:** comma-separated entries such as `Small:12, Medium:15, Large:18`. Use `One size` when no size selection is needed.

Changing a seed product in `Program.cs` only affects a new database. Existing products use the values already stored in `data/catalog.db`; edit those through the worker portal or perform a deliberate database migration.

## Worker portal security

Worker portal credentials are stored outside the source code. Set them from the project folder:

```powershell
dotnet user-secrets set "Worker:Username" "your-worker-username"
dotnet user-secrets set "Worker:Password" "your-long-random-password"
```

The portal locks login attempts from a client after five failures for five minutes. Authentication uses an HttpOnly, SameSite cookie and enables the Secure flag automatically when served over HTTPS.

## Contact form email

The contact form sends messages to `digitaldesignduo02@gmail.com` through Gmail SMTP. Create a Google App Password for the sending account, then store the mail settings as local .NET user secrets from the project folder:

The form also uses a hidden bot-trap field and limits repeated submissions from the same client to one every 30 seconds.

```powershell
dotnet user-secrets set "Email:SmtpHost" "smtp.gmail.com"
dotnet user-secrets set "Email:SmtpPort" "587"
dotnet user-secrets set "Email:Username" "digitaldesignduo02@gmail.com"
dotnet user-secrets set "Email:Password" "your-16-character-app-password"
dotnet user-secrets set "Email:Recipient" "digitaldesignduo02@gmail.com"
```

Use a Google App Password, not the normal Google account password. The contact endpoint uses the visitor's address as `Reply-To`, so replies can go directly back to the shopper.

## Product storage

Products published through the worker portal are stored in `data/catalog.db`, a SQLite database created automatically when the server starts. The built-in products are seeded into that database the first time it is created, and worker-created products remain available after server restarts. Uploaded product images are stored in `data/product-images/uploads/` so Angular builds cannot remove them.

Back up both `data/catalog.db` and `data/product-images/uploads/` before moving or redeploying the application. Do not delete `data/catalog.db` unless you intentionally want to recreate the catalog.

## SEO and public URLs

The public domain is configured in these files:

- `ClientApp/src/index.html`: canonical URL, Open Graph metadata, and organization JSON-LD.
- `ClientApp/public/robots.txt`: crawler rules and sitemap URL.
- `ClientApp/public/sitemap.xml`: public URLs submitted to search engines.

If the domain changes, update all three files and review any Stripe success and cancellation URLs. Keep `/api/` and `/workers` out of the sitemap.

## Local development

The application has two processes during development:

1. Start the ASP.NET backend from the project root:

```powershell
$env:ASPNETCORE_ENVIRONMENT = "Development"
dotnet run --urls http://localhost:5000
```

2. In a second terminal, start Angular from `ClientApp`:

```powershell
cd ClientApp
npm install
npm start
```

Open `http://localhost:4200`. The Angular proxy forwards `/api` and product-image requests to the backend at port `5000`. For a deployable build, run `npm run build`; the output is copied to the backend `wwwroot` directory.

After backend source or user-secret changes, restart the backend. After Angular source changes, use the development server or run a new production build.

## Pre-deployment checklist

- Use a live Stripe key only through the hosting provider's secret/environment-variable system.
- Configure worker credentials and Gmail SMTP credentials outside source control.
- Deploy behind HTTPS so authentication cookies are secure.
- Persist the `data/` directory, including the SQLite database and uploaded images.
- Test a successful payment, cancelled checkout, free shipping, paper-goods shipping, and handmade shipping.
- Verify that the deployed `/robots.txt` and `/sitemap.xml` use the real public domain.
- Confirm the Stripe webhook records line items and shipping addresses.
- Confirm employees can filter open/fulfilled orders, see shipping details, mark an order fulfilled, and notify the buyer with item details.