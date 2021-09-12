(function($)
{
    $.SimFlexgridSave=function(tableobj,url)
    {
        var g = 
        {
			prefs:
            {
                coldata:{},

                savetoServer:function()
                {
                    $.ajax({        
                        type: "POST",
                        url: url,
                        data: this.coldata
                    }); 
                },
                saveColState:function()
                {
                    var hDiv = tableobj.grid.hDiv;
                    var prefs = this;
                    prefs.coldata['colorder'] = {};
                    prefs.coldata['colwidths']={};
                    prefs.coldata['colvisible']={};
                    
                    var idx=1;
                    $('th',hDiv).each(function(n)
                    {
                        var name = $(this).attr('abbr');
                        prefs.coldata['colorder'][name] = idx++;
                        prefs.coldata['colwidths'][name] = $('div',this).css('width');
                        prefs.coldata['colvisible'][name] = ($(this).css('display') == 'none')?false:true;
                    });
                    
                    prefs.savetoServer();
                }
			}
        };   
        
        $(tableobj).bind('OnColresize',function(event, nw,n,th)
        {
            g.prefs.saveColState();
        });
        
        $(tableobj).bind('OnColVisible',function(event,visible, n,th)
        {
            g.prefs.saveColState();
        });
        
        $(tableobj).bind('OnDragCol',function(event,hDiv)
        {
            g.prefs.saveColState();
        });
    }
})(jQuery);