(() => {
    'use strict';

    const formatBytes = (bytes) => {
        if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let index = 0;
        while (value >= 1024 && index < units.length - 1) {
            value /= 1024;
            index += 1;
        }
        return `${value.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
    };

    const form = document.getElementById('upload-form');
    const input = document.getElementById('file-input');
    const dropzone = document.getElementById('dropzone');
    const selectedBox = document.getElementById('selected-files');
    const selectedList = document.getElementById('selected-files-list');
    const progress = document.getElementById('upload-progress');
    const progressBar = document.getElementById('upload-progress-bar');
    const status = document.getElementById('form-status');
    const button = document.getElementById('upload-button');

    const setStatus = (message, type = '') => {
        if (!status) return;
        status.textContent = message;
        status.classList.remove('error', 'ok');
        if (type) status.classList.add(type);
    };

    const renderSelected = () => {
        if (!input || !selectedBox || !selectedList) return;

        selectedList.replaceChildren();
        const files = Array.from(input.files || []);
        selectedBox.hidden = files.length === 0;

        files.forEach((file) => {
            const item = document.createElement('li');
            item.textContent = `${file.name} · ${formatBytes(file.size)}`;
            selectedList.appendChild(item);
        });
    };

    if (input) {
        input.addEventListener('change', renderSelected);
    }

    if (dropzone && input) {
        const setDragging = (active) => dropzone.classList.toggle('is-dragging', active);

        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                event.stopPropagation();
                setDragging(true);
            });
        });

        ['dragleave', 'dragend'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                event.stopPropagation();
                setDragging(false);
            });
        });

        dropzone.addEventListener('drop', (event) => {
            event.preventDefault();
            event.stopPropagation();
            setDragging(false);

            if (!event.dataTransfer || event.dataTransfer.files.length === 0) return;
            input.files = event.dataTransfer.files;
            renderSelected();
            setStatus(`เลือก ${event.dataTransfer.files.length} ไฟล์แล้ว`, 'ok');
        });
    }

    if (form && input && button) {
        form.addEventListener('submit', (event) => {
            if (input.files.length === 0) {
                event.preventDefault();
                setStatus('กรุณาเลือกไฟล์ก่อนอัปโหลด', 'error');
                return;
            }

            if (!window.FormData || !window.XMLHttpRequest) {
                return;
            }

            event.preventDefault();
            button.disabled = true;
            if (status) status.classList.remove('error', 'ok');
            setStatus('กำลังอัปโหลด…');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action') || 'upload.php', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.addEventListener('progress', (progressEvent) => {
                if (!progressEvent.lengthComputable || !progress || !progressBar) return;
                const percent = Math.min(100, Math.round((progressEvent.loaded / progressEvent.total) * 100));
                progress.hidden = false;
                progressBar.style.width = `${percent}%`;
                setStatus(`กำลังอัปโหลด ${percent}%`);
            });

            xhr.addEventListener('load', () => {
                let payload = null;
                try {
                    payload = JSON.parse(xhr.responseText);
                } catch (error) {
                    payload = null;
                }

                if (xhr.status >= 200 && xhr.status < 300) {
                    if (payload && payload.ok === false && payload.message) {
                        setStatus(payload.message, 'error');
                        button.disabled = false;
                        return;
                    }

                    if (progressBar) progressBar.style.width = '100%';
                    setStatus('อัปโหลดเสร็จแล้ว กำลังโหลดรายการใหม่…', 'ok');
                    window.location.href = 'upload.php#files';
                    return;
                }

                setStatus(payload && payload.message ? payload.message : `เซิร์ฟเวอร์ตอบกลับ ${xhr.status}`, 'error');
                button.disabled = false;
            });

            xhr.addEventListener('error', () => {
                setStatus('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ — ตรวจสอบว่าเซิร์ฟเวอร์ยังทำงานอยู่', 'error');
                button.disabled = false;
            });

            xhr.send(new FormData(form));
        });
    }

    document.querySelectorAll('form[data-confirm]').forEach((confirmForm) => {
        confirmForm.addEventListener('submit', (event) => {
            const message = confirmForm.getAttribute('data-confirm') || 'ยืนยันการทำรายการ?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
})();
