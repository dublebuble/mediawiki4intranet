function WikiHelper()
{
    this.hnd = new WikiContentHandler();
    Components
        .classes["@mozilla.org/uriloader;1"]
        .getService(Components.interfaces.nsIURILoader)
        .registerContentListener(this);
}

WikiHelper.prototype =
{
    QueryInterface: function(iid)
    {
        if (!iid.equals(Components.interfaces.nsIURIContentListener) &&
            !iid.equals(Components.interfaces.nsISupports))
            return Components.results.NS_ERROR_NO_INTERFACE;
        return this;
    },
    loadCookie: null,
    parentContentListener: null,
    hnd: null,
    canHandleContent: function(aContentType, aIsContentPreferred, aDesiredContentType)
    {
        if (!this.hnd.indialog && aContentType.substr(0,29) == 'application/x-external-editor')
            return true;
        return false;
    },
    isPreferred: function(aContentType, aDesiredContentType)
    {
        if (!this.hnd.indialog && aContentType.substr(0,29) == 'application/x-external-editor')
            return true;
        return false;
    },
    onStartURIOpen: function(aURI)
    {
        return false;
    },
    doContent: function(aContentType, aIsContentPreferred, aRequest, aContentHandler)
    {
        aContentHandler.value = this.hnd.indialog ? null : this.hnd;
        return false;
    },
};

function WikiContentHandler()
{
    this.ud = new UrlDownloader();
    this.ud.callbackObject = this;
    this.ud.onFinish =
        function(urls, outfiles, obj)
        {
            obj.finish(urls, outfiles);
        };
}

WikiContentHandler.prototype =
{
    QueryInterface: function(iid)
    {
        if (!iid.equals(Components.interfaces.nsIStreamListener) &&
            !iid.equals(Components.interfaces.nsIRequestObserver) &&
            !iid.equals(Components.interfaces.nsIObserver) &&
            !iid.equals(Components.interfaces.nsIDOMEventListener) &&
            !iid.equals(Components.interfaces.nsISupports))
            return Components.results.NS_ERROR_NO_INTERFACE;
        return this;
    },
    data: '',
    ud: null,
    url: '',
    ext: '',
    script: '',
    outfile: null,
    post: null,
    indialog: false,
    onStartRequest: function(aRequest, aContext)
    {
        this.data = '';
        this.url = '';
        this.ext = '';
        this.script = '';
    },
    onDataAvailable: function(aRequest, aContext, aInputStream, aOffset, aCount)
    {
        var c = '';
        try
        {
            if ('data' in aInputStream)
                c = aInputStream.data;
            else
                aInputStream.read(c, aCount);
        }
        catch (err) {}
        this.data += c;
    },
    onStopRequest: function(aRequest, aContext, aStatusCode)
    {
        var d = this.data.split("\n");
        var re = /^\s*(.*?)\s*=\s*(.*?)\s*$/i;
        var v;
        for (var i = 0; i < d.length; i++)
        {
            if (v = d[i].match(re))
            {
                if (v[1] == 'Script')
                    this.script = v[2];
                else if (v[1] == 'Extension')
                    this.ext = v[2];
                else if (v[1] == 'URL')
                    this.url = v[2];
            }
        }
        if (this.url == '' || this.script == '')
        {
            alert(wgWikiHelperStrings['error']);
            //alert('Invalid helper file from MediaWiki: line URL=... or line Script=... not found');
            return;
        }
        var f = Components
            .classes["@mozilla.org/file/local;1"]
            .createInstance(Components.interfaces.nsILocalFile);
        f.initWithPath(this.getTmpDir());
        f.appendRelativePath("wikihelper-" + hex_md5(this.url) + "." + this.ext);
        this.ud.saveURIList([this.url], [f]);
    },
    getTmpDir: function()
    {
        return Components
            .classes['@mozilla.org/file/directory_service;1']
            .getService(Components.interfaces.nsIProperties)
            .get("UChrm",Components.interfaces.nsILocalFile)
            .path;
    },
    handleEvent: function(st)
    {
        if (this.post.readyState == 4)
        {
            Application.activeWindow.activeTab.load(Application.activeWindow.activeTab.uri);
//            alert(this.post.responseText);
        }
    },
    observe: function(aSubject, aTopic, aData)
    {
        if (aTopic != 'process-finished' && aTopic != 'process-finished-pre-3.5')
            return;
        // показываем окно для ввода описания изменений
        this.indialog = true;
        openDialog('chrome://wikihelper/content/entercomment.xul', 'entercomment', 'chrome,centerscreen', this);
    },
    save: function(comment)
    {
        if (comment == null || comment == '')
            return;
        // читаем файл
        var is = Components
            .classes["@mozilla.org/network/file-input-stream;1"]
            .createInstance(Components.interfaces.nsIFileInputStream);
        is.init(this.outfile, 0x01, null, null);
        var bis = Components
            .classes["@mozilla.org/binaryinputstream;1"]
            .createInstance(Components.interfaces.nsIBinaryInputStream);
        bis.setInputStream(is);
        var data;
        var sz = is.available();
        try
        {
            data = bis.readBytes(sz);
        }
        catch(err)
        {
            alert(err);
        }
        is.close();
        // заливаем файл
        var filename = /([^\/]*)$/i;
        filename = this.url.match(filename);
        filename = filename[1];
        var post = Components
            .classes["@mozilla.org/xmlextras/xmlhttprequest;1"]
            .createInstance();
        this.post = post;
        post.open('POST', this.script+'?title=Special:Upload&wpSourceType=file&wpIgnoreWarning=true&wpDestFile='+filename, true);
        post.withCredentials = true;
        post.setRequestHeader('Content-Type', 'multipart/form-data; boundary=--upload0962783');
        post.onreadystatechange = this;
        data =
            "----upload0962783\n"+
            "Content-Disposition: form-data; name=\"wpUploadDescription\"\n\n"+
            comment+"\n"+
            "----upload0962783\n"+
            "Content-Disposition: form-data; name=\"wpUpload\"\n\n"+
            "Upload file\n"+
            "----upload0962783\n"+
            "Content-Disposition: form-data; name=\"wpUploadFile\"; filename=\""+this.outfile.path+"\"\n"+
            "Content-Length: "+sz+"\n\n"+
            data+
            "\n----upload0962783--\n";
        post.sendAsBinary(data);
    },
    finish: function(urls, outfiles)
    {
        var mimeservice = Components
            .classes["@mozilla.org/mime;1"]
            .getService(Components.interfaces.nsIMIMEService);
        var type;
        for (var i in this.ud.types)
            type = this.ud.types[i];
        var ed = '';
        try
        {
            var mime = mimeservice.getFromTypeAndExtension(type, '.'+this.ext);
            ed = mime.preferredApplicationHandler;
            ed = ed.QueryInterface(Components.interfaces.nsILocalHandlerApp);
            ed = ed.executable.path;
        }
        catch(err) { ed = ''; }
        ed = prompt(wgWikiHelperStrings['enterpath'], ed);
        if (!ed)
            return;
        // запускаем редактор
        var ef = Components
            .classes["@mozilla.org/file/local;1"]
            .createInstance(Components.interfaces.nsILocalFile);
        ef.initWithPath(ed);
        if (!ef.exists())
        {
            alert(wgWikiHelperStrings['editornotfound'].replace('XXX', ef.path));
            //alert("Editor '" + ef.path + "' not found!");
            return;
        }
        if (!ef.isExecutable())
        {
            alert(wgWikiHelperStrings['notexecutable'].replace('XXX', ef.path));
            //alert("File '" + ef.path + "' is not executable!");
            return;
        }
        this.outfile = outfiles[0];
        var process = Components
            .classes["@mozilla.org/process/util;1"]
            .createInstance(Components.interfaces.nsIProcess);
        process.init(ef);
        var args = [outfiles[0].path];
        try
        {
            process.runAsync(args, args.length, this, false);
        }
        catch (err)
        {
            // runAsync, вероятно, недоступен (версия Firefox < 3.5)
            process.run(false, args, args.length);
            this.observe(process, 'process-finished-pre-3.5', null);
        }
    },
};
