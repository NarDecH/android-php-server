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

    // ---------- QR code สำหรับเปิดเซิร์ฟเวอร์จากเครื่องอื่น (assets/vendor/qrcode.js) ----------

    document.querySelectorAll('[data-qr]').forEach((box) => {
        const value = box.getAttribute('data-qr') || '';

        if (!value || typeof window.qrcode !== 'function') return;

        try {
            const qr = window.qrcode(0, 'M');
            qr.addData(value);
            qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });

            const svg = box.querySelector('svg');
            if (svg) {
                svg.setAttribute('role', 'img');
                svg.setAttribute('aria-label', `QR code สำหรับ ${value}`);
            }
        } catch (error) {
            /* ถ้าสร้าง QR ไม่ได้ ปล่อยให้ URL ที่แสดงอยู่ข้าง ๆ ใช้แทนได้ */
        }
    });

    // ---------- log viewer: กรองบรรทัด ----------

    const logView = document.getElementById('log-view');

    if (logView) {
        const filterInput = document.getElementById('log-filter');
        const filterCount = document.getElementById('log-filter-count');
        let fullText = logView.textContent;
        let term = '';

        const render = (stick = false) => {
            if (!term) {
                if (logView.textContent !== fullText) logView.textContent = fullText;
                if (filterCount) filterCount.textContent = '';
            } else {
                const lines = fullText.split('\n');
                const matched = lines.filter((line) => line.toLowerCase().includes(term));
                logView.textContent = matched.join('\n');

                if (filterCount) {
                    filterCount.textContent = matched.length
                        ? `${matched.length} / ${lines.length} บรรทัด`
                        : 'ไม่พบบรรทัดที่ตรง';
                }
            }

            if (stick) logView.scrollTop = logView.scrollHeight;
        };

        // โหมดติดตามอัตโนมัติส่งข้อความใหม่ผ่านฟังก์ชันนี้ เพื่อให้ตัวกรองยังทำงานต่อ
        logView.logText = fullText;
        logView.applyLogText = (text, stick = false) => {
            fullText = text;
            logView.logText = text;
            render(stick);
        };

        if (filterInput) {
            filterInput.addEventListener('input', () => {
                term = filterInput.value.trim().toLowerCase();
                render();
                logView.scrollTop = 0;
            });
        }

        // คัดลอกข้อความที่แสดงอยู่ (หลังกรอง) ไปยังคลิปบอร์ด
        const copyButton = document.getElementById('log-copy');

        if (copyButton) {
            const resetLabel = () => {
                copyButton.textContent = 'คัดลอก';
            };

            copyButton.addEventListener('click', async () => {
                const text = logView.textContent;

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(text);
                    } else {
                        // http:// บน LAN ไม่ใช่ secure context — ใช้ textarea ชั่วคราวแทน
                        const area = document.createElement('textarea');
                        area.value = text;
                        area.setAttribute('readonly', '');
                        area.style.position = 'fixed';
                        area.style.opacity = '0';
                        document.body.appendChild(area);
                        area.select();
                        const copied = document.execCommand('copy');
                        document.body.removeChild(area);

                        if (!copied) throw new Error('copy failed');
                    }

                    copyButton.textContent = 'คัดลอกแล้ว ✓';
                } catch (error) {
                    copyButton.textContent = 'คัดลอกไม่ได้';
                }

                window.setTimeout(resetLabel, 2000);
            });
        }
    }

    // ---------- log viewer: ติดตามบรรทัดใหม่ทุก 5 วินาที ----------

    const liveToggle = document.getElementById('log-live-toggle');

    if (liveToggle && logView) {
        const endpoint = liveToggle.getAttribute('data-endpoint') || '';
        const interval = Math.max(2000, Number(liveToggle.getAttribute('data-interval')) || 5000);
        const seconds = Math.round(interval / 1000);
        const label = document.getElementById('log-live-label');
        const statusEl = document.getElementById('log-live-status');
        const meta = document.getElementById('log-meta');
        const mtime = document.getElementById('log-mtime');
        const storageKey = 'phpsrv.logLive';
        let timer = null;
        let busy = false;

        const setStatus = (text) => {
            if (statusEl) statusEl.textContent = text ? ` · ${text}` : '';
        };

        const nearBottom = () => logView.scrollHeight - logView.scrollTop - logView.clientHeight < 48;

        const applyPayload = (data) => {
            if (!data || typeof data.text !== 'string') return;
            if (data.text === logView.logText) {
                setStatus('ไม่มีบรรทัดใหม่');
                return;
            }

            const stick = nearBottom();
            logView.applyLogText(data.text, stick);
            if (meta) meta.textContent = `${data.count} บรรทัด · ${data.size}`;
            if (mtime && data.mtime) mtime.textContent = data.mtime;
            setStatus(`อัปเดต ${new Date().toLocaleTimeString('th-TH')}`);
        };

        const poll = async () => {
            if (busy || !endpoint || document.hidden) return;
            busy = true;

            try {
                const response = await fetch(endpoint, {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const type = response.headers.get('content-type') || '';

                if (!response.ok) {
                    throw new Error(`เซิร์ฟเวอร์ตอบกลับ ${response.status}`);
                }
                if (!type.includes('application/json')) {
                    throw new Error('ต้องล็อกอินใหม่');
                }

                applyPayload(await response.json());
            } catch (error) {
                setStatus(error instanceof Error && error.message ? error.message : 'ขาดการเชื่อมต่อ');
            } finally {
                busy = false;
            }
        };

        const setLive = (on, remember = true) => {
            liveToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            if (label) label.textContent = on ? `ติดตามทุก ${seconds} วิ` : 'ติดตามอัตโนมัติ';

            if (on) {
                if (!timer) timer = window.setInterval(poll, interval);
                poll();
            } else {
                if (timer) window.clearInterval(timer);
                timer = null;
                setStatus('');
            }

            if (remember) {
                try {
                    window.localStorage.setItem(storageKey, on ? '1' : '0');
                } catch (error) {
                    /* โหมดส่วนตัวบางเบราว์เซอร์ปิด localStorage */
                }
            }
        };

        liveToggle.addEventListener('click', () => {
            setLive(liveToggle.getAttribute('aria-pressed') !== 'true');
        });

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && liveToggle.getAttribute('aria-pressed') === 'true') poll();
        });

        try {
            if (window.localStorage.getItem(storageKey) === '1') setLive(true, false);
        } catch (error) {
            /* ไม่มี localStorage ก็เริ่มแบบปิด */
        }
    }
})();
