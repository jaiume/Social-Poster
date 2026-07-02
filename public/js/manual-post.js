(function() {
    const actions = document.getElementById('post-preview-actions');
    if (!actions) {
        return;
    }

    const textEl = document.getElementById('post-preview-text');
    const imageUrl = actions.dataset.imageUrl || '';
    const postId = actions.dataset.postId || 'image';

    function extensionForMime(mime) {
        const map = {
            'image/jpeg': 'jpg',
            'image/png': 'png',
            'image/webp': 'webp',
            'image/gif': 'gif',
        };
        return map[mime] || 'png';
    }

    function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(value);
        }

        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
        } finally {
            document.body.removeChild(textarea);
        }

        return Promise.resolve();
    }

    function downloadBlob(blob, filename) {
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(objectUrl);
    }

    async function fetchImageBlob(url) {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error('Could not load image.');
        }
        return response.blob();
    }

    async function copyImage(url) {
        const blob = await fetchImageBlob(url);
        const type = blob.type || 'image/png';

        if (window.isSecureContext && navigator.clipboard && typeof ClipboardItem !== 'undefined') {
            await navigator.clipboard.write([new ClipboardItem({ [type]: blob })]);
            return 'copied';
        }

        downloadBlob(blob, 'post-' + postId + '-image.' + extensionForMime(type));
        return 'downloaded';
    }

    const copyTextBtn = document.getElementById('copy-post-text');
    if (copyTextBtn && textEl) {
        copyTextBtn.addEventListener('click', function() {
            const text = textEl.textContent.trim();
            if (text === '' || text === '—') {
                alert('No text to copy.');
                return;
            }
            copyText(text).catch(function() {
                alert('Could not copy text to clipboard.');
            });
        });
    }

    const copyImageBtn = document.getElementById('copy-post-image');
    if (copyImageBtn && imageUrl) {
        copyImageBtn.addEventListener('click', function() {
            copyImage(imageUrl)
                .then(function(result) {
                    if (result === 'downloaded') {
                        alert('Image copy to clipboard needs HTTPS. The image was downloaded instead — upload or paste it from your downloads folder.');
                    }
                })
                .catch(function() {
                    const downloadLink = document.getElementById('download-post-image');
                    if (downloadLink) {
                        downloadLink.click();
                        alert('Could not copy image to clipboard. The image was downloaded instead.');
                        return;
                    }
                    alert('Could not copy image. Try Download image instead.');
                });
        });
    }
})();
