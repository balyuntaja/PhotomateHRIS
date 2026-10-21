(function () {
    const imageExtensions = /\.(jpe?g|png|webp)$/i;
    const pdfExtensions = /\.pdf$/i;

    const fileType = (file) => file?.type || file?.file?.type || '';
    const fileName = (file) => file?.filename || file?.file?.name || '';
    const fileUrl = (file) => (typeof file?.source === 'string' ? file.source : null);

    const isImageFile = (file) =>
        fileType(file).startsWith('image/') || imageExtensions.test(fileName(file));

    const isPdfFile = (file) =>
        fileType(file) === 'application/pdf' || pdfExtensions.test(fileName(file));

    window.evidencePreview = function (initial) {
        initial = initial || {};

        return {
            previewUrl: initial.url || null,
            previewName: initial.name || null,
            previewIsImage: !!initial.isImage,
            previewIsPdf: !!initial.isPdf,
            previewLoading: !!initial.isImage && !!initial.url,
            previewError: false,
            isUploading: false,
            uploadError: false,

            updateEvidencePreview(detail) {
                detail = detail || {};

                if ('url' in detail) {
                    const urlChanged = this.previewUrl !== detail.url;

                    this.previewUrl = detail.url;
                    this.previewError = false;

                    if (urlChanged) {
                        this.previewLoading = !!this.previewIsImage && !!detail.url;
                    }
                }

                if ('name' in detail) {
                    this.previewName = detail.name;
                }

                if ('isImage' in detail) {
                    this.previewIsImage = !!detail.isImage;
                }

                if ('isPdf' in detail) {
                    this.previewIsPdf = !!detail.isPdf;
                }

                if ('uploading' in detail) {
                    this.isUploading = !!detail.uploading;
                }

                if ('uploadError' in detail) {
                    this.uploadError = !!detail.uploadError;
                }

                if (detail.uploadError) {
                    this.previewError = false;
                }
            },

            watchPreviewImage(image) {
                let attempts = 0;

                const check = () => {
                    if (!image) {
                        return;
                    }

                    if (image.complete) {
                        this.previewLoading = false;
                        this.previewError = image.naturalWidth === 0;

                        return;
                    }

                    if (++attempts >= 200) {
                        this.previewLoading = false;
                        this.previewError = true;

                        return;
                    }

                    window.setTimeout(check, 50);
                };

                check();
            },

            clearEvidencePreview() {
                this.previewUrl = null;
                this.previewName = null;
                this.previewIsImage = false;
                this.previewIsPdf = false;
                this.previewLoading = false;
                this.previewError = false;
                this.isUploading = false;
                this.uploadError = false;
            },

            retryEvidencePreview() {
                if (!this.previewUrl || this.previewUrl.startsWith('blob:')) {
                    return;
                }

                this.previewError = false;
                this.previewLoading = true;

                const url = new URL(this.previewUrl, window.location.origin);
                url.searchParams.set('retry', Date.now().toString());

                this.previewUrl = url.toString();
            },
        };
    };

    window.evidenceUploadBridge = function (component, element, eventName, clearEventName, stored) {
        stored = stored || {};

        const componentData = (element?._x_dataStack || []).find((data) => 'pond' in data) || component;

        let objectUrl = null;
        let uploading = false;
        let uploadError = false;

        let shouldUpdateState = componentData.shouldUpdateState;
        let forceStateUpdate = false;

        const getFiles = () => componentData.pond?.getFiles?.() || [];
        const hasLocalFiles = () => getFiles().length > 0;

        // Filament turns the state watcher back on as soon as the upload
        // finishes. Since temporary uploads have no public URL yet, the watcher
        // would replace the FilePond list with an empty one and revert the file
        // before it can be saved. Keep the watcher paused while a local upload
        // is still shown; removing the file explicitly re-enables it.
        Object.defineProperty(Alpine.raw(componentData), 'shouldUpdateState', {
            configurable: true,
            get: () => shouldUpdateState,
            set: (value) => {
                if (value === true && ! forceStateUpdate && hasLocalFiles()) {
                    return;
                }

                shouldUpdateState = value;
            },
        });

        const allowStateUpdate = () => {
            forceStateUpdate = true;
            componentData.shouldUpdateState = true;

            window.setTimeout(() => {
                forceStateUpdate = false;
            }, 0);
        };

        const publish = (detail) => {
            window.dispatchEvent(new CustomEvent(eventName, { detail }));
        };

        const releaseObjectUrl = () => {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        };

        const sync = () => {
            const files = component.pond?.getFiles?.() || [];
            const file = files[files.length - 1] || null;

            if (!file) {
                releaseObjectUrl();

                publish({
                    url: stored.url || null,
                    name: stored.name || null,
                    isImage: !!stored.isImage,
                    isPdf: !!stored.isPdf,
                    uploading,
                    uploadError,
                });

                return;
            }

            const isPdf = isPdfFile(file);
            const isImage = !isPdf && isImageFile(file);

            let url = null;

            if (file.file instanceof File) {
                releaseObjectUrl();
                objectUrl = URL.createObjectURL(file.file);
                url = objectUrl;
            } else {
                url = fileUrl(file) || stored.url || null;
            }

            publish({
                url,
                name: fileName(file) || null,
                isImage,
                isPdf,
                uploading,
                uploadError,
            });
        };

        const attach = () => {
            if (!component.pond) {
                window.setTimeout(attach, 25);

                return;
            }

            component.pond.on('processfilestart', () => {
                uploading = true;
                uploadError = false;
                publish({ uploading, uploadError });
            });

            component.pond.on('processfile', () => {
                uploading = false;
                uploadError = false;

                // Keep the freshly uploaded local file visible. Replacing the
                // FilePond list from Livewire state while the temporary upload
                // has no preview URL makes the item disappear before saving.
                componentData.shouldUpdateState = false;

                sync();
            });

            component.pond.on('processfileabort', () => {
                uploading = false;
                allowStateUpdate();
                publish({ uploading });
            });

            component.pond.on('processfileerror', () => {
                uploading = false;
                uploadError = true;
                allowStateUpdate();
                publish({ uploading, uploadError });
            });

            component.pond.on('processfilerevert', () => {
                allowStateUpdate();
            });

            component.pond.on('addfile', sync);
            component.pond.on('removefile', () => {
                allowStateUpdate();
                sync();
            });
            component.pond.on('updatefiles', sync);

            sync();
        };

        const clear = () => {
            stored = {};
            releaseObjectUrl();

            allowStateUpdate();

            if (component.pond?.getFiles().length) {
                component.pond.removeFiles();
            }

            publish({
                url: null,
                name: null,
                isImage: false,
                isPdf: false,
                uploading: false,
                uploadError: false,
            });
        };

        window.addEventListener(clearEventName, clear);
        window.addEventListener('evidence-upload-reset', clear);

        const originalDestroy = componentData.destroy;

        componentData.destroy = function () {
            window.removeEventListener(clearEventName, clear);
            window.removeEventListener('evidence-upload-reset', clear);
            releaseObjectUrl();
            originalDestroy?.call(this);
        };

        attach();
    };
})();
