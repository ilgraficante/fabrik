Fabrik.getWindow=function(b){if(Fabrik.Windows[b.id]){Fabrik.Windows[b.id].open();Fabrik.Windows[b.id].setOptions(b);Fabrik.Windows[b.id].loadContent()}else{var a=b.type?b.type:"";switch(a){case"redirect":Fabrik.Windows[b.id]=new Fabrik.RedirectWindow(b);break;case"modal":Fabrik.Windows[b.id]=new Fabrik.Modal(b);break;case"":default:Fabrik.Windows[b.id]=new Fabrik.Window(b);break}}return Fabrik.Windows[b.id]};Fabrik.Window=new Class({Implements:[Events,Options],options:{id:"FabrikWindow",title:"&nbsp;",container:false,loadMethod:"html",contentURL:"",createShowOverLay:false,width:300,height:300,loadHeight:100,expandable:true,offset_x:null,offset_y:null,onContentLoaded:function(){this.fitToContent()},destroy:false},modal:false,classSuffix:"",expanded:false,initialize:function(a){this.setOptions(a);this.makeWindow()},makeWindow:function(){var l,c,e;var g=[];var q={width:this.options.width+"px",height:this.options.height+10+"px"};q.top=typeOf(this.options.offset_y)!=="null"?window.getScroll().y+this.options.offset_y:window.getSize().y/2+window.getScroll().y;q.left=typeOf(this.options.offset_x)!=="null"?window.getScroll().x+this.options.offset_x:window.getSize().x/2+window.getScroll().x-this.options.width/2;this.window=new Element("div",{id:this.options.id,"class":"fabrikWindow "+this.classSuffix}).setStyles(q);this.contentWrapperEl=this.window;var s=Fabrik.iconGen.create(icon.cross);var a=function(d){this.close(d)}.bind(this);var i=new Element("a",{href:"#","class":"close",events:{click:a}});s.inject(i);var f="handlelabel";if(!this.modal){f+=" draggable";l=new Element("div",{"class":"bottomBar"});c=new Element("div",{"class":"dragger"});var o=Fabrik.iconGen.create(icon.resize,{scale:0.8,rotate:0,shadow:{color:"#fff",translate:{x:0,y:1}},fill:{color:["#999","#666"]}});o.inject(c);l.adopt(c)}var h=new Element("span",{"class":f}).set("text",this.options.title);g.push(h);var j=Fabrik.iconGen.create(icon.expand,{scale:0.4,fill:{color:["#666666","#999999"]}});if(this.options.expandable&&this.modal===false){e=new Element("a",{href:"#","class":"expand",events:{click:function(d){this.expand(d)}.bind(this)}}).adopt(j);g.push(e)}g.push(i);this.handle=this.getHandle().adopt(g);var b=15;var p=15;var n=this.options.height-b-p;if(n<this.options.loadHeight){n=this.options.loadHeight}this.contentWrapperEl=new Element("div.contentWrapper",{styles:{height:n+"px"}});var r=new Element("div",{"class":"itemContent"});this.contentEl=new Element("div",{"class":"itemContentPadder"});r.adopt(this.contentEl);this.contentWrapperEl.adopt(r);if(this.modal){var k=this.options.height-30;cw=this.options.width;this.contentWrapperEl.setStyles({height:k+"px",width:cw+"px"});this.window.adopt([this.handle,this.contentWrapperEl])}else{this.window.adopt([this.handle,this.contentWrapperEl,l]);this.window.makeResizable({handle:c,onDrag:function(){Fabrik.fireEvent("fabrik.window.resized",this.window);this.drawWindow()}.bind(this)});var m={handle:this.handle};m.onComplete=function(){Fabrik.fireEvent("fabrik.window.moved",this.window);this.drawWindow()}.bind(this);m.container=this.options.container?document.id(this.options.container):null;this.window.makeDraggable(m)}document.id(document.body).adopt(this.window);this.loadContent()},expand:function(b){b.stop();if(!this.expanded){this.expanded=true;var a=window.getSize();this.unexpanded=this.window.getCoordinates();this.window.setPosition({x:0,y:0}).setStyles({width:a.x,height:a.y})}else{this.window.setPosition({x:this.unexpanded.left,y:this.unexpanded.top}).setStyles({width:this.unexpanded.width,height:this.unexpanded.height});this.expanded=false}this.drawWindow()},getHandle:function(){return new Element("div",{"class":"handle draggable"})},loadContent:function(){var b;switch(this.options.loadMethod){case"html":if(typeOf(this.options.content)==="null"){fconsole("no content option set for window.html");this.close();return}if(typeOf(this.options.content)==="element"){this.options.content.inject(this.contentEl.empty())}else{this.contentEl.set("html",this.options.content)}this.fireEvent("onContentLoaded",[this]);break;case"xhr":b=this.window.getElement(".itemContent");Fabrik.loader.start(b);new Request.HTML({url:this.options.contentURL,data:{fabrik_window_id:this.options.id},update:b,onSuccess:function(){Fabrik.loader.stop(b);this.fireEvent("onContentLoaded",[this])}.bind(this)}).post();break;case"iframe":var c=this.options.height-40;var a=this.contentEl.getScrollSize().x+40<window.getWidth()?this.contentEl.getScrollSize().x+40:window.getWidth();b=this.window.getElement(".itemContent");Fabrik.loader.start(b);if(this.iframeEl){this.iframeEl.dispose()}this.iframeEl=new Element("iframe",{id:this.options.id+"_iframe",name:this.options.id+"_iframe","class":"fabrikWindowIframe",src:this.options.contentURL,marginwidth:0,marginheight:0,frameBorder:0,scrolling:"auto",styles:{height:c+"px",width:a}}).injectInside(this.window.getElement(".itemContent"));this.iframeEl.hide();this.iframeEl.addEvent("load",function(d){Fabrik.loader.stop(this.window.getElement(".itemContent"));this.iframeEl.show();this.fireEvent("onContentLoaded",[this])}.bind(this));break}},drawWindow:function(){this.contentWrapperEl.setStyle("height",this.window.getDimensions().height-this.handle.getDimensions().height-25);this.contentWrapperEl.setStyle("width",this.window.getDimensions().width-2);if(this.options.loadMethod==="iframe"){this.iframeEl.setStyle("height",this.contentWrapperEl.offsetHeight-40);this.iframeEl.setStyle("width",this.contentWrapperEl.offsetWidth-10)}},fitToContent:function(){if(!this.options.offset_y){var d=new Fx.Scroll(window).toElement(this.window)}if(this.options.loadMethod!=="iframe"){var c=this.window.getElement(".itemContent");var b=c.getScrollSize().y<window.getHeight()?c.getScrollSize().y:window.getHeight();var a=c.getScrollSize().x+17<window.getWidth()?c.getScrollSize().x+17:window.getWidth();this.window.setStyle("height",b);this.window.setStyle("width",a)}this.drawWindow()},center:function(){this.window.makeCenter()},close:function(a){if(a){a.stop()}if(this.options.destroy){this.window.destroy();delete (Fabrik.Windows[this.options.id])}else{this.window.fade("hide")}},open:function(a){if(a){a.stop()}this.window.fade("show")}});Fabrik.Modal=new Class({Extends:Fabrik.Window,modal:true,classSuffix:"fabrikWindow-modal",getHandle:function(){return new Element("div",{"class":"handle"})}});Fabrik.RedirectWindow=new Class({Extends:Fabrik.Window,initialize:function(c){var a={id:"redirect",title:c.title?c.title:"",loadMethod:b,width:c.width?c.width:300,height:c.height?c.height:320,minimizable:false,collapsible:true};a.id="redirect";c=Object.merge(a,c);var b;c.loadMethod="xhr";if(!c.contentURL.contains(Fabrik.liveSite)&&(c.contentURL.contains("http://")||c.contentURL.contains("https://"))){c.loadMethod="iframe"}else{if(!c.contentURL.contains("tmpl=component")){c.contentURL+=c.contentURL.contains("?")?"&tmpl=component":"?tmpl=component"}}this.setOptions(c);this.makeWindow()}});