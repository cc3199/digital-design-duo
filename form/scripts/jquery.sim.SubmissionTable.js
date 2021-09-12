(function($)
{
  $.fn.submissionTable = function(options_param) 
  {
    var defaults =
    {
        url:'',
        field_objs:[],
        title:"Form submissions",
        delete_button:true,
        fieldsel:'',
        detail_view:
        {
            title:"Detailed View"
        }
    };
    var options = $.extend(defaults,options_param||{});
    var thisobj = 
    {
        gridid:'',
        tableobj:null,
        gridobj:null,
        selectFirstRowforDetailView:null,
        selectLastRowforDetailView:null
    };
    
    var grid_opts=
        {
            url:'',
            dataType: 'json',
            colNames:[],
            colModel : [],
            searchitems:[],
            buttons :[],
            sortname: "ID",
            sortorder: "desc",
            usepager: true,
            showToggleBtn:false,
            title: "",
            useRp: true,
            rp: 10,
            showTableToggleBtn: false,
            resizable: true,
            width: 0,/*Fit the browser window*/
            height: 270,
            singleSelect: true,
        }; 
        
    this.each(function(){ setup_grid(this); });
    
    function setup_grid(tableobj)
    {
        //thisobj.gridobj = tableobj;
        thisobj.tableobj = tableobj;
        thisobj.gridid = $(tableobj).attr('id');
        $.getJSON( options.url,{getfields:'y'},function(fields)
            {
                if(0 == grid_opts.width)
                {
                    grid_opts.width = $(window).width()-50;
                }
                grid_opts.url = options.url;
                grid_opts.title = options.title;
                grid_opts.onSuccess = onGridLoadSuccess;

                options.field_objs = fields;
                
                add_fields(fields);
                add_buttons();
                $.SimFlexgridSave(tableobj,$.jscComposeURL(options.url,{'sfm_save_grid_opts':'yes'}));
                $(tableobj).flexigrid(grid_opts);
                
            });
    }
    
    function onGridLoadSuccess(g)
    {
        thisobj.gridobj = g.gDiv;
        
        if(null != thisobj.selectFirstRowforDetailView)
        {
            var $row = $('div.bDiv tr',thisobj.gridobj).first().addClass('trSelected');
            reLoadDetailedView(thisobj.selectFirstRowforDetailView,getIDofRow($row));
            thisobj.selectFirstRowforDetailView=null;
        }
        else if(null != thisobj.selectLastRowforDetailView)
        {
            var $row = $('div.bDiv tr',thisobj.gridobj).last().addClass('trSelected');
            reLoadDetailedView(thisobj.selectLastRowforDetailView,getIDofRow($row));
            thisobj.selectLastRowforDetailView = null;
        }
        
        $('tr',thisobj.gridobj).dblclick(function(event)
        {
            $(thisobj.gridobj).removeClass('trSelected');
            $(this).addClass('trSelected');
            ShowDetailedView(this);
        });
        //image popup
        AttachImagePopupHandler(thisobj.gridobj);
        
        $('div.pDiv div.pSearch',thisobj.gridobj).css({width:'120px'}).html('Search').append('<span></span>');
    }
    
    function AttachImagePopupHandler(containerobj)
    {
        $('a.sfm_tnail_link',containerobj).click(function(event)
        {
            event.preventDefault();
            var imgurl = $(this).attr('href');
            var tmpimg=new Image();//pre-load image
            tmpimg.onload=function()
            {
                $('<img />')
                .attr('src',imgurl)
                .appendTo('<div></div>').dialog(
                    {
                      position: 'top',
                      show:'puff',
                      hide:'puff',
                      modal: true,
                      resizable: true,
                      draggable: true,
                      width: tmpimg.width,
                      height: tmpimg.height,
                      title: 'View Image',
                      close: function(ev, ui) { $(this).remove(); }
                    });
           };
           tmpimg.src = imgurl;
        });    
    }
    
    function create_field_selector(name,dispname,selected)
    {
        if(options.fieldsel.length <= 0){return;}
        
        var $sel = $('#'+options.fieldsel);
            
        $opt = $('<option />', { value: name,text: dispname});
        
        if(selected)
        {
            $opt.attr('selected','selected');
        }
        
        $opt.appendTo($sel);
        return $sel;
    }
    
    function add_fields(fields)
    {
        var $sel=null;
        $.each(fields,function(f,field)
        {
            if($.type(field)!='object'){return;}
            var dispname = $.jscIsSet(field['dispname']) ? field['dispname']:field['name'];
            var width = $.jscIsSet(field['width']) ? parseInt(field['width'],10):150;
            
            var visible = $.jscIsSet(field['visible']) ? field['visible']:true;
            visible = (visible == 'false') ? false:true;
            grid_opts.colNames.push(field['name']);
            grid_opts.colModel.push({display: dispname, name : field['name'], 
                        width : width, sortable : true, 
                        align: 'left', hide:!visible});
            grid_opts.searchitems.push({display: dispname, name : field['name']});
            $sel = create_field_selector(field['name'],dispname,visible);
        });
        if($sel)
        {
            $sel.multiselect('option','click',on_field_sel_click);
            $sel.multiselect('option','header',false);
            $sel.multiselect('option','position',{ my: 'left bottom',at: 'left top'});

            $sel.multiselect('refresh');
        }
    }
    
    function on_field_sel_click(event,ui)
    {
        var th  = $("div.hDiv th[abbr='"+ui.value+"']",thisobj.gridobj).get(0);
        var cid = $("div.hDiv thead th",thisobj.gridobj).index(th);
        $(thisobj.tableobj).flexToggleCol(cid,ui.checked?true:false);
    }
    
    function add_buttons()
    {
        grid_opts.buttons.push({name: 'View', bclass: 'button-view', onpress : OnButtonPress});
    }
    
    function OnButtonPress(cmd,grid)
    {
        var row = $('.trSelected', grid).get(0);
        
        if('View' == cmd)
        {
            ShowDetailedView(row);
        }
    }
    
    function ShowDetailedView(row)
    {
        var url = getSingleRecURL(getIDofRow(row));
        var popup_opts=
            { modal: true,height: $(window).height()-50, width: 'auto', title:options.detail_view.title,
                buttons: { 'Print':OnDetailViewPrint,'Prev' : OnDetailViewPrev,'Next': OnDetailViewNext}};
                
        $.jscPopup(url,popup_opts,
                {formatTxt:onDetailedViewReady,popupShown:detailViewShown});     
    }
    function getSingleRecURL(id)
    {
        return $.jscComposeURL(options.url,{getrec:id});
    }
    function getIDofRow(row)
    {
        return $('td[abbr="ID"] >div',row).html();
    }
    
    function detailViewShown($dlg)
    {
        bindDetailviewEvents($dlg);
    }
    
    function moveToNextGridPage($dlgobj)
    {
        thisobj.selectFirstRowforDetailView = $dlgobj;
        $('.pNext',thisobj.gridobj).trigger('click');
    }
    
    function moveToLastGridPage($dlgobj)
    {
        thisobj.selectLastRowforDetailView = $dlgobj;
        $('.pPrev',thisobj.gridobj).trigger('click');
    }
    
    function OnDetailViewPrint()
    {
        var $dlgobj=$(this);
        var $current = $('.trSelected',thisobj.gridobj);
        if($current.length == 0){return;}
        var url = $.jscComposeURL(options.url,{printrec:getIDofRow($current)});
        var newwnd = window.open(url,'_newtab');
        
    }
    function OnDetailViewNext()
    {
        var $dlgobj=$(this);
        var $current = $('.trSelected',thisobj.gridobj);
        if($current.length == 0){return;}
        var $next = $current.next();
        if($next.length == 0){ moveToNextGridPage($dlgobj); return;}
        $current.removeClass('trSelected');
        $next.addClass('trSelected');
        reLoadDetailedView($dlgobj,getIDofRow($next));    
    }
    
    function OnDetailViewPrev()
    {
        var $dlgobj=$(this);
        var $current = $('.trSelected',thisobj.gridobj);
        if($current.length == 0){return;}
        var $prev = $current.prev();
        if($prev.length == 0){ moveToLastGridPage($dlgobj); return;}
        $current.removeClass('trSelected');
        $prev.addClass('trSelected');
        reLoadDetailedView($dlgobj,getIDofRow($prev));    
    }
    
    function bindDetailviewEvents($dlgobj)
    {
        //image popup
        AttachImagePopupHandler($dlgobj);
    }
    
    function reLoadDetailedView($dlgobj,id)
    {
      $.get(getSingleRecURL(id),{},function(responsetxt)
        {
            $('#datatable',$dlgobj).html(formatDetailedView(responsetxt));
        });    
    }
    
    function getFieldProp(name,prop)
    {
        for(var f=0;f<options.field_objs.length;f++)
        {
            if(options.field_objs[f]['name'] == name)
            {
                var field = options.field_objs[f];
                var ret = $.jscIsSet(field[prop])?field[prop]:null;
                return ret;
            }
        }
        return null;
    }
    
    function makePopupContent(table_content)
    {
        var poup_html=
            '<div><div id="detailview">'+
            '<div id="datatable"></div>'+
            '</div></div>';    
        $popuphtml = $(poup_html);    
        $('#datatable',$popuphtml).append(table_content);
        return $popuphtml.html();            
    }
    function onDetailedViewReady(jsontext)
    {
        var content = formatDetailedView(jsontext);
        return makePopupContent(content);
    }
    
    function formatDetailedView(jsontext)
    {
        var jsonobj = $.parseJSONObj(jsontext);
        if(null == jsonobj)
        {
            return jsontext;
        }
        var dispRecObj={};
        
        //console.log(options.field_objs);
        $.each(options.field_objs, function(f,field)
        {
            if(!$.jscIsSet(jsonobj[field.name])){ return; }
            
            var dispname = getFieldProp(field.name,'dispname');
            if(dispname == null)
            {
                dispname = field.name;
            }
            dispRecObj[dispname]=jsonobj[field.name];
        });
        
        return $.jscFormatToTable(dispRecObj,'tableid');
    }
  }
})(jQuery);