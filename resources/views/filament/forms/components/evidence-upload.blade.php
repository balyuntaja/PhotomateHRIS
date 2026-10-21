@php
    use App\Filament\Forms\Components\EvidenceUpload;
    use Filament\Support\Enums\Alignment;
    use Filament\Support\Facades\FilamentView;
    use Illuminate\Support\Str;

    $imageCropAspectRatio = $getImageCropAspectRatio();
    $imageResizeTargetHeight = $getImageResizeTargetHeight();
    $imageResizeTargetWidth = $getImageResizeTargetWidth();
    $isAvatar = $isAvatar();
    $statePath = $getStatePath();
    $isDisabled = $isDisabled();

    $alignment = $getAlignment() ?? Alignment::Start;

    if (! $alignment instanceof Alignment) {
        $alignment = filled($alignment) ? (Alignment::tryFrom($alignment) ?? $alignment) : null;
    }

    $storedPath = $getStoredPath();
    $storedStatePath = Str::replaceLast('evidence_upload', 'evidence_path', $statePath);

    $storedUrl = null;
    $storedName = null;
    $storedIsImage = false;
    $storedIsPdf = false;

    if (filled($storedPath)) {
        $storedUrl = EvidenceUpload::relativeUrl($getDisk(), $storedPath);
        $storedName = basename($storedPath);
        $storedExtension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
        $storedIsImage = in_array($storedExtension, ['jpg', 'jpeg', 'png', 'webp'], true);
        $storedIsPdf = $storedExtension === 'pdf';
    }

    $previewEvent = 'evidence-preview-' . substr(md5($getId()), 0, 12);
    $previewClearEvent = 'evidence-clear-' . substr(md5($getId()), 0, 12);

    $previewState = [
        'url' => $storedUrl,
        'name' => $storedName,
        'isImage' => $storedIsImage,
        'isPdf' => $storedIsPdf,
    ];
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
    :label-sr-only="$isLabelHidden()"
>
    <div
        @if (FilamentView::hasSpaMode())
            {{-- format-ignore-start --}}x-load="visible || event (ax-modal-opened)"{{-- format-ignore-end --}}
        @else
            x-load
        @endif
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('file-upload', 'filament/forms') }}"
        x-data="fileUploadFormComponent({
                    acceptedFileTypes: @js($getAcceptedFileTypes()),
                    imageEditorEmptyFillColor: @js($getImageEditorEmptyFillColor()),
                    imageEditorMode: @js($getImageEditorMode()),
                    imageEditorViewportHeight: @js($getImageEditorViewportHeight()),
                    imageEditorViewportWidth: @js($getImageEditorViewportWidth()),
                    deleteUploadedFileUsing: async (fileKey) => {
                        return await $wire.deleteUploadedFile(@js($statePath), fileKey)
                    },
                    getUploadedFilesUsing: async () => ({}),
                    hasImageEditor: false,
                    hasCircleCropper: false,
                    canEditSvgs: @js($canEditSvgs()),
                    isSvgEditingConfirmed: @js($isSvgEditingConfirmed()),
                    confirmSvgEditingMessage: @js(__('filament-forms::components.file_upload.editor.svg.messages.confirmation')),
                    disabledSvgEditingMessage: @js(__('filament-forms::components.file_upload.editor.svg.messages.disabled')),
                    imageCropAspectRatio: @js($imageCropAspectRatio),
                    imagePreviewHeight: @js($getImagePreviewHeight()),
                    imageResizeMode: @js($getImageResizeMode()),
                    imageResizeTargetHeight: @js($imageResizeTargetHeight),
                    imageResizeTargetWidth: @js($imageResizeTargetWidth),
                    imageResizeUpscale: @js($getImageResizeUpscale()),
                    isAvatar: @js($isAvatar),
                    isDeletable: @js($isDeletable()),
                    isDisabled: @js($isDisabled),
                    isDownloadable: @js($isDownloadable()),
                    isMultiple: @js($isMultiple()),
                    isOpenable: @js($isOpenable()),
                    isPreviewable: @js($isPreviewable()),
                    isReorderable: @js($isReorderable()),
                    itemPanelAspectRatio: @js($getItemPanelAspectRatio()),
                    loadingIndicatorPosition: @js($getLoadingIndicatorPosition()),
                    locale: @js(app()->getLocale()),
                    panelAspectRatio: @js($getPanelAspectRatio()),
                    panelLayout: @js($getPanelLayout()),
                    placeholder: @js($getPlaceholder()),
                    maxFiles: @js($getMaxFiles()),
                    maxSize: @js(($size = $getMaxSize()) ? "{$size}KB" : null),
                    minSize: @js(($size = $getMinSize()) ? "{$size}KB" : null),
                    mimeTypeMap: @js($getMimeTypeMap()),
                    maxParallelUploads: @js($getMaxParallelUploads()),
                    removeUploadedFileUsing: async (fileKey) => {
                        return await $wire.removeFormUploadedFile(@js($statePath), fileKey)
                    },
                    removeUploadedFileButtonPosition: @js($getRemoveUploadedFileButtonPosition()),
                    reorderUploadedFilesUsing: async (files) => {
                        return await $wire.reorderFormUploadedFiles(@js($statePath), files)
                    },
                    shouldAppendFiles: @js($shouldAppendFiles()),
                    shouldOrientImageFromExif: @js($shouldOrientImagesFromExif()),
                    shouldTransformImage: @js($imageCropAspectRatio || $imageResizeTargetHeight || $imageResizeTargetWidth),
                    state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
                    uploadButtonPosition: @js($getUploadButtonPosition()),
                    uploadingMessage: @js($getUploadingMessage()),
                    uploadProgressIndicatorPosition: @js($getUploadProgressIndicatorPosition()),
                    uploadUsing: (fileKey, file, success, error, progress) => {
                        $wire.upload(
                            `{{ $statePath }}.${fileKey}`,
                            file,
                            () => {
                                success(fileKey)
                            },
                            error,
                            (progressEvent) => {
                                progress(true, progressEvent.detail.progress, 100)
                            },
                        )
                    },
                })"
        x-init="evidenceUploadBridge($data, $el, @js($previewEvent), @js($previewClearEvent), @js($previewState))"
        wire:ignore
        {{
            $attributes
                ->merge([
                    'id' => $getId(),
                ], escape: false)
                ->merge($getExtraAttributes(), escape: false)
                ->merge($getExtraAlpineAttributes(), escape: false)
                ->class([
                    'fi-fo-file-upload flex flex-col gap-y-2 [&_.filepond--root]:font-sans',
                    match ($alignment) {
                        Alignment::Start, Alignment::Left => 'items-start',
                        Alignment::Center => 'items-center',
                        Alignment::End, Alignment::Right => 'items-end',
                        default => $alignment,
                    },
                ])
        }}
    >
        <div
            x-data="evidencePreview(@js($previewState))"
            x-on:{{ $previewEvent }}.window="updateEvidencePreview($event.detail)"
            x-show="previewUrl || previewIsPdf || isUploading || uploadError"
            x-cloak
            class="relative w-full overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5"
        >
            <button
                type="button"
                x-show="! isUploading && (previewUrl || previewIsPdf)"
                x-cloak
                x-on:click="
                    $wire.set(@js($storedStatePath), null);
                    window.dispatchEvent(new CustomEvent(@js($previewClearEvent)));
                    clearEvidencePreview();
                "
                class="rounded-lg px-2.5 py-1 text-xs font-semibold text-white"
                style="position: absolute; top: 0.75rem; left: 0.75rem; z-index: 1; background-color: rgba(3, 7, 18, 0.8);"
            >
                Hapus
            </button>

            <template x-if="previewIsImage">
                <div class="relative">
                    <img
                        x-ref="evidenceImage"
                        x-bind:src="previewUrl"
                        x-on:load="previewLoading = false; previewError = false"
                        x-on:error="previewLoading = false; previewError = true"
                        x-effect="previewUrl; watchPreviewImage($el)"
                        alt="Preview bukti transaksi"
                        class="w-full bg-white dark:bg-gray-950"
                        style="max-height: 16rem; object-fit: contain;"
                    />

                    <div
                        x-show="previewLoading"
                        class="flex items-center justify-center"
                        style="position: absolute; inset: 0; background-color: rgba(3, 7, 18, 0.45);"
                    >
                        <svg class="h-6 w-6 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                    </div>

                    <div
                        x-show="previewError"
                        x-cloak
                        class="flex flex-col items-center justify-center gap-2 p-4 text-center text-sm text-white"
                        style="position: absolute; inset: 0; background-color: rgba(3, 7, 18, 0.8);"
                    >
                        <span>Preview bukti transaksi gagal dimuat.</span>

                        <button
                            type="button"
                            x-on:click="retryEvidencePreview()"
                            class="rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold text-white ring-1 ring-inset ring-white/25 transition hover:bg-white/25"
                        >
                            Coba lagi
                        </button>
                    </div>
                </div>
            </template>

            <template x-if="previewIsPdf">
                <div class="flex items-center gap-3 p-4">
                    <div
                        class="flex shrink-0 items-center justify-center rounded-lg bg-danger-50 text-danger-600 ring-1 ring-inset ring-danger-200 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-500/30"
                        style="height: 3.5rem; width: 3rem;"
                    >
                        <span class="text-xs font-bold tracking-wide">PDF</span>
                    </div>

                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-950 dark:text-white" x-text="previewName"></p>

                        <a
                            x-show="previewUrl"
                            x-bind:href="previewUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            Lihat PDF
                        </a>
                    </div>
                </div>
            </template>

            <div
                x-show="uploadError"
                x-cloak
                class="border-t border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-400"
            >
                Upload bukti transaksi gagal. Gunakan tombol Retry pada file di bawah untuk mencoba lagi.
            </div>

            <div
                x-show="isUploading"
                x-cloak
                class="inline-flex items-center gap-1.5 rounded-full bg-gray-950/80 px-2.5 py-1 text-xs font-medium text-white"
                style="position: absolute; top: 0.75rem; right: 0.75rem;"
            >
                <svg class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                Mengunggah
            </div>
        </div>

        <div
            @class([
                'w-32' => $isAvatar,
                'w-full' => ! $isAvatar,
            ])
        >
            <input
                x-ref="input"
                {{
                    $getExtraInputAttributeBag()
                        ->merge([
                            'disabled' => $isDisabled,
                            'multiple' => $isMultiple(),
                            'type' => 'file',
                        ], escape: false)
                }}
            />
        </div>

        <div
            x-show="error"
            x-text="error"
            x-cloak
            class="text-sm text-danger-600 dark:text-danger-400"
        ></div>
    </div>
</x-dynamic-component>
