;(function load($) {

    if ($.klear.checkDeps && !$.klear.checkDeps(['$.klearmatrix.module','$.ui.form'],load)) {

        return;
    }

    var __namespace__ = "klearmatrix.gallery";

    $.widget("klearmatrix.gallery", $.klearmatrix.module,  {
        isDialog: false,
        layout: null,
        options: {
            data : null,
            moduleName: 'gallery',
            context: null,
            _wym: null,
        },
        _super: $.klearmatrix.module.prototype,
        _create : function() {
            this._super._create.apply(this);
        },
        _init: function() {

            if ($(this.element.klearModule("getModuleDialog")).context) {

                this.isDialog = true;
            }

            if (this.options.data.templateName) {

                var $appliedTemplate = this._loadTemplate(this.options.data.templateName);

                if (this.isDialog) {

                    this.layout = $(this.element.klearModule("getModuleDialog"));

                } else {

                    this.layout = $(this.element.klearModule("getPanel"));
                }

                if (this.isDialog) {

                    this.layout.css({"display" : "block", "height": "auto"});
                }

                this.layout.html($appliedTemplate);

                if (this.isDialog) {

                    this.layout.find("a[title]").tooltip(
                        {
                           position: {
                            using: function( position, feedback ) {
                              $(this).css("z-index", 1100);
                              $( this ).css( position );
                              $( "<div>" ).appendTo( this );
                            }
                           }
                        }
                    );
                }

                this._registerBaseEvents();
                this._registerGalleryEvents();
                this._initStyles();
            }
        },
        _registerGalleryEvents: function () {

            var _self = this;
            var context = this.layout;

            $(context).find("a[href]").on("click", function (e) {

                e.preventDefault();
                e.stopPropagation();
                _self._openGallery($(this));
            });

            this._initNewImageHandler();
            this._initImageEditHandler();
            this._initNewGalleryHandler();
            this._initDeleteHandler();
            this._initImageSizeEditHandler();
            this._initContext();
        },

       _initNewImageHandler: function () {

            var newImg = $(this.layout).find("a.newImg");
            if (newImg.length > 0 ) {

                this._initUploader();
                newImg.css("cursor", "pointer").off("click").on("click", function (e) {

                    e.preventDefault();
                    e.stopPropagation();

                    $(this).parent().children().toggle();

                    var containners = $(this).parents(".klear-gallery").find("div.galleryContent").children();
                    containners.toggle();
                });
            }
       },


       _initNewGalleryHandler: function () {

            var _self = this;
            var newGallery = $(this.layout).find("a.newGallery");
            if (newGallery.length > 0 ) {

                newGallery.css("cursor", "pointer").off("click").on("click", function (e) {

                    e.preventDefault();
                    e.stopPropagation();

                    var containners = $(this).parents(".klear-gallery").children();
                    containners.toggle();

                    //reset form values
                    containners.filter(":visible").find("input").attr("value", "");
                });
            }

            $(this.layout).find("a.editGallery").off('click').on('click', function (e) {

                e.preventDefault();
                e.stopPropagation();

                var newGalleryButton = newGallery;

                $.getJSON($(this).attr("href"), function (resp) {

                    newGalleryButton.trigger('click');
                    var formulario = $(newGalleryButton).parents(".klear-gallery").find("form:visible");

                    var primaryKey = resp.data.galleryData[resp.data.galleryStructure.pkName];
                    formulario.find("input[name=pk]").attr("value", primaryKey);

                    var titleField = resp.data.galleryStructure.field;
                    if (resp.data.galleryStructure.isMultilang) {
                        var languages = resp.data.galleryStructure.availableLangs;
                        for (var languageIdx in languages) {
                            var val = resp.data.galleryData[titleField + "_" + languages[languageIdx]];
                            formulario.find("input[name="+titleField + languages[languageIdx]  + "]").attr("value", val);
                        }
                    } else {
                        var val = resp.data.galleryData[titleField];
                        formulario.find("input[name="+ titleField + "]").attr("value", val);
                    }
                });
            });
       },

       _initDeleteHandler: function () {

            var removeImg = $(this.layout).find("a.delete");

            if (removeImg.length > 0 ) {

                removeImg.css("cursor", "pointer").off("click").on("click", function (e) {

                    e.preventDefault();
                    e.stopPropagation();

                    var _self = $(this);

                    $( "<div>¿Está seguro?</div>" ).dialog({
                        resizable: false,
                        height:140,
                        modal: true,
                        buttons: {
                            "Eliminar": function() {

                                $( this ).dialog( "close" );
                                $.getJSON(_self.attr("rel"), function (resp) {

                                    if (resp.success) {

                                        if (_self.parent().get(0).tagName.toLowerCase() == "td") {
                                            _self.parents("tr").remove();

                                        } else {

                                            _self.parents("div.topMenu").find("a.return").trigger("click");
                                        }
                                    } else {

                                        //TODO: Mostrar mensaje de error
                                    }
                                });
                            },
                            Cancel: function() {

                                $( this ).dialog( "close" );
                            }
                        }
                    });

                });
            }
       },

       _initImageEditHandler: function () {

            var _self = this;

            $(this.layout).find("a.edit").css("cursor", "pointer").off("click").on("click", function (e) {

                e.preventDefault();
                e.stopPropagation();

                $(this).parent().children().toggle();

                var wrapper = $(this).parents(".klear-gallery");
                wrapper.find("form").children().toggle();
                wrapper.find("form").submit(function (event) {

                    event.preventDefault();
                    event.stopPropagation();

                    $(this).parents("div.klear-gallery").find("a.save").trigger("click");
                });

                wrapper.find("form input").focus()
                                          .select();

                var selectableImage = wrapper.find("img.selectable");
                wrapper.find("div.container, div.assistant, p.sizeSelector, div.insert").toggle();

                if ($(this).hasClass("init")) {

                    selectableImage.data("original-width", selectableImage.width()).css("float", "left").animate({"width" : 100}, 500);
                    wrapper.find("div.photoTitle").addClass("edit");

                } else {

                    selectableImage.css("float", "none").animate({"width" : selectableImage.data("original-width")}, 500);
                    wrapper.find("div.photoTitle").removeClass("edit");
                }
            });

            $(this.layout).find("a.save").css("cursor", "pointer").off("click").on("click", function (e) {

                var formulario = $(this).parents("div.klear-gallery").find("form");

                var formValue = formulario.find("input").val();

                formulario.find("span").html(formValue);
                formulario.parents("fieldset").find("legend").html(formValue);

                $.ajax({
                    type: "POST",
                    url: formulario.attr("action"),
                    data: formulario.serialize(),
                    success: function (response) {

                        if (response.success) {

                            $(_self.layout).find("a.edit.cancel").trigger("click");
                        }
                    },
                    error: function (error) {

                        console.log("error", error);
                    },
                    dataType: 'json'
                });
            });
       },

       _initUploader: function () {

            var _self = this;
            if($(".galleryContent", $(this.layout)).length > 0) {

                qqOptions = {
                    element: $(".galleryContent div.uploader", $(this.layout)).get(0),
                    action: this.options.data.baseUrl + "&section=picture&action=upload",
                    params: {uploadTo: this.options.data.galleryId},
                    allowedExtensions: ['jpeg', 'jpg', 'png', 'gif'],
                    dragAndDrop: true,
                    hideDropzones: false,
                    multiple: false,
                    template: '<div class="qq-uploader">\
                                 <div class="qq-gallery-upload-drop-area">\
                                    <span>Arrastra los archivos aquí o</span><br />\
                                    <div class="qq-gallery-upload-button"><input type="button" value="Elegir archivo" /></div>\
                                 </div>\
                                 <ul class="qq-gallery-upload-list"></ul>\
                               </div>',

                    classes: {
                        button: 'qq-gallery-upload-button',
                        drop: 'qq-gallery-upload-drop-area',
                        dropActive: 'qq-upload-drop-area-active',
                        dropDisabled: 'qq-upload-drop-area-disabled',
                        list: 'qq-gallery-upload-list',
                        progressBar: 'qq-progress-bar',
                        file: 'qq-upload-file',
                        spinner: 'qq-upload-spinner',
                        finished: 'qq-upload-finished',
                        size: 'qq-upload-size',
                        cancel: 'qq-upload-cancel',
                        failText: 'qq-upload-failed-text',
                    },

                    onComplete : function(id, fileName, result) {

                        var $list = $(".qq-gallery-upload-list", $(this._element).parent());
                        var parentContext = _self;

                        if (result.error) {

                            $list.empty();
                            $(_self).klearModule("showDialogError", result.message, {title : $.translate("ERROR",[__namespace__])});
                            return;
                        }

                        $list.html('');
                        var targetLink = _self.options.data.baseUrl + "&section=picture&action=load&pk=" + result.picturePk;
                        _self._openGallery($("<a/>").attr("href", targetLink), function () {
                            $(parentContext.layout).find("a.edit:visible").trigger("click");
                        });
                    },

                    showMessage : function(message) {

                        /*if (typeof(message) == 'string') {
                            $(".qq-upload-list",$(this.element)).html('');
                            $(_self).klearModule("showDialogError", message, {title : $.translate("ERROR",[__namespace__])});
                        }*/
                    },

                    error: function(code, fileName){

                    }
                };

                var uploader = new qq.FileUploader(qqOptions);
            }
       },

       _initImageSizeEditHandler: function () {

            var _self = this;
            $(this.layout).find("a.submit").off("click").on("click", function (e) {

                e.preventDefault();
                e.stopPropagation();

                var formulario = $(this).parents("form");

                $.ajax({
                    type: "POST",
                    url: formulario.attr("action"),
                    data: formulario.serialize(),
                    success: function (response) {

                        if (response.success) {

                            $(_self.layout).find("a.return").trigger("click");
                        }
                    },
                    error: function (error) {

                        console.log("error", error);
                    },
                    dataType: 'json'
                });
            });
       },

       _initContext: function () {
           if (this.isDialog) {
                this._initDialogContext();
           } else {
               this._initScreenContext();
           }
       },

       _initScreenContext: function () {

            var _self = this;
            $(this.layout).find("img.selectable").css("cursor", "pointer").click(function () {

                //Abrir imagen en una nueva pestaña a tamaño original
                var imgSrc = $(this).data("handler").replace("#sizeId#", "0");
                window.open(imgSrc, "newTab");
            });
       },

       _initDialogContext: function() {

            var _self = this;

            if (_self.isDialog) {

                var sizeSelector = $(_self.layout).find("p.sizeSelector");
                var insertImageButton = $(_self.layout).find("div.insert");

                sizeSelector.show();
                insertImageButton.show();
                sizeSelector.find("ul.selectboxit-options").css("right", "0");

                insertImageButton.off('click', 'a').on('click', 'a', function (e) {

                    e.preventDefault();
                    e.stopPropagation();
                    $(_self.layout).find("img.selectable").trigger('click');
                });

                $(_self.layout).find("img.selectable").css("cursor", "pointer").off("click").on("click",
                    function () {
                        if (_self.options._wym) {
                            _self._insertImageIntoWymEditor.apply(_self, [this]);
                        } else if (window.tinymce) {
                            _self._insertImageIntoTinyEditor.apply(_self, [this]);
                        }

                        var closeButton = _self.layout.next().find("div.ui-dialog-buttonset button");
                        closeButton.trigger("click");
                    }
                  );
            }
       },

       _insertImageIntoTinyEditor: function (image) {

            var sizeOption = $("select.sizeSelector option:selected", this.layout);
            var sizeOptionValue = sizeOption.attr("value") ? sizeOption.attr("value") : 0;
            var imgAlt = $(image).data("alt");
            var imgUri = $(image).data("uri").replace("#sizeId#", sizeOptionValue);
            var imgSrc = $(image).data("baseurl") + imgUri;

            var img = '<img alt="'+ imgAlt +'" src="'+ imgSrc +'" />';
            window.tinymce.EditorManager.activeEditor.insertContent(img);
       },

       _insertImageIntoWymEditor: function (image) {

          var wym = this.options._wym;
          var selectedImg = null;

          if ( wym._selected_image ) {

              selectedImg = wym._selected_image;

          } else if ( wym._wym._selected_image ) {

              selectedImg = wym._wym._selected_image;
          }

          var sizeOption = $("select.sizeSelector option:selected", this.layout);
          var sizeOptionValue = sizeOption.attr("value") ? sizeOption.attr("value") : 0;
          var imgAlt = $(image).data("alt");
          var imgUri = $(image).data("uri").replace("#sizeId#", sizeOptionValue);
          var imgSrc = $(image).data("baseurl") + imgUri;

          var execResp = this.options._wym._doc.execCommand("insertImage", false, imgSrc);
          var container =  this.options._wym.selected();

          if (container && container.tagName.toLowerCase() === WYMeditor.BODY) {
             this.options._wym._exec(WYMeditor.FORMAT_BLOCK, WYMeditor.P);
          }

          var injectedImg = $(this.options._wym._doc).find("img[src='"+ imgSrc +"']");
          injectedImg.attr({"data-uri": imgUri, "alt" : imgAlt});
          $(this.options._wym._doc).trigger("keyup");
       },

        _openGallery: function (node, callback) {

            var _self = this;
            var onLoadTrigger = callback || function () {};

            $.getJSON(node.attr("href") + "&isDialog=" + this.isDialog, function (resp) {

                if (resp.data.templateName) {

                    _self.options.data = resp.data;
                    var $appliedTemplate = _self._loadTemplate(resp.data.templateName);

                    if (! _self.isDialog) {

                        //_self.layout.css("width", "50%");

                    } else {

                        $($appliedTemplate).find("div.assistant").show();
                    }

                    if (_self.isDialog) {

                        _self.layout.klearModule("moduleDialog").moduleDialog("updateContent", $appliedTemplate);

                    } else {

                        _self.layout.html($appliedTemplate);
                    }

                    var parentContext = _self;
                    setTimeout(function () {
                        parentContext._registerBaseEvents();
                        parentContext._registerGalleryEvents();
                        parentContext._initStyles();
                        onLoadTrigger();
                    }, 500);
                }
            });
        },

        _initStyles: function () {

            var tr = $('table.kMatrix tr', this.layout);
            $("td:not(:last-child)", tr).on('mouseenter mouseleave',function() {

                $("td:not(:last-child)", $(this).parent('tr')).toggleClass("ui-state-highlight");
                $(this).parent('tr').toggleClass("pointer");
                $("a.option.default", $(this).parent('tr')).toggleClass("ui-state-active");

            }).on('mouseup',function(e) {
                // Haciendo toda la tupla clickable para la default option
                e.stopPropagation();
                e.preventDefault();

                $("a:first", $(this).parents("tr")).trigger("click");
            });
        }
    });

    $.widget.bridge("klearMatrixGallery", $.klearmatrix.genericscreen);

})(jQuery);