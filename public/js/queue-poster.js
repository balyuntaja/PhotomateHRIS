/**
 * Photomate Minimalist Queue Poster Generator (1200x1800 px)
 */
window.downloadHighResPoster = async function (options) {
    const { url, name, venue, date, code, btnId } = options;
    const btn = document.getElementById(btnId);
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="inline-block animate-spin mr-2">⏳</span> Memproses Poster Minimalis 1200x1800...';
    }

    try {
        const width = 1200;
        const height = 1800;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Canvas 2D context tidak didukung');

        // Helper for rounded rectangles
        const roundRect = (x, y, w, h, r) => {
            ctx.beginPath();
            ctx.moveTo(x + r, y);
            ctx.lineTo(x + w - r, y);
            ctx.quadraticCurveTo(x + w, y, x + w, y + r);
            ctx.lineTo(x + w, y + h - r);
            ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
            ctx.lineTo(x + r, y + h);
            ctx.quadraticCurveTo(x, y + h, x, y + h - r);
            ctx.lineTo(x, y + r);
            ctx.quadraticCurveTo(x, y, x + r, y);
            ctx.closePath();
        };

        // 1. Minimalist Studio Clean Background (Soft Warm Off-White)
        const bgGrad = ctx.createLinearGradient(0, 0, 0, height);
        bgGrad.addColorStop(0, '#FFFFFF');
        bgGrad.addColorStop(1, '#F8FAFC');
        ctx.fillStyle = bgGrad;
        ctx.fillRect(0, 0, width, height);

        // 2. Elegant Minimalist Frame Inset
        ctx.save();
        ctx.strokeStyle = '#E2E8F0';
        ctx.lineWidth = 2.5;
        roundRect(44, 44, width - 88, height - 88, 28);
        ctx.stroke();

        // Subtle decorative corner marks
        ctx.strokeStyle = '#94A3B8';
        ctx.lineWidth = 3;
        // Top-left
        ctx.beginPath(); ctx.moveTo(60, 90); ctx.lineTo(60, 60); ctx.lineTo(90, 60); ctx.stroke();
        // Top-right
        ctx.beginPath(); ctx.moveTo(width - 90, 60); ctx.lineTo(width - 60, 60); ctx.lineTo(width - 60, 90); ctx.stroke();
        // Bottom-left
        ctx.beginPath(); ctx.moveTo(60, height - 90); ctx.lineTo(60, height - 60); ctx.lineTo(90, height - 60); ctx.stroke();
        // Bottom-right
        ctx.beginPath(); ctx.moveTo(width - 90, height - 60); ctx.lineTo(width - 60, height - 60); ctx.lineTo(width - 60, height - 90); ctx.stroke();
        ctx.restore();

        // 3. Top Branding Header (Minimalist & Modern)
        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = '#0F172A'; // Deep Charcoal
        ctx.font = '900 40px system-ui, -apple-system, sans-serif';
        ctx.fillText('PHOTOMATE', 600, 140);

        ctx.fillStyle = '#64748B'; // Slate 500
        ctx.font = '600 16px system-ui, -apple-system, sans-serif';
        ctx.fillText('S I S T E M   A N T R E A N   D I G I T A L', 600, 175);
        ctx.restore();

        // Subtle divider line
        ctx.save();
        ctx.strokeStyle = '#E2E8F0';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(450, 205);
        ctx.lineTo(750, 205);
        ctx.stroke();
        ctx.restore();

        // 4. Event Title (Prominent, Elegant Typography)
        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = '#0F172A';
        ctx.font = 'bold 56px system-ui, -apple-system, sans-serif';

        const words = (name || 'Event Photomate').split(' ');
        let curLine = '';
        const titleLines = [];
        const maxTitleWidth = 980;
        for (let n = 0; n < words.length; n++) {
            const testLine = curLine + words[n] + ' ';
            const metrics = ctx.measureText(testLine);
            if (metrics.width > maxTitleWidth && n > 0) {
                titleLines.push(curLine);
                curLine = words[n] + ' ';
            } else {
                curLine = testLine;
            }
        }
        titleLines.push(curLine);

        const displayLines = titleLines.slice(0, 2);
        let titleStartY = displayLines.length === 1 ? 300 : 280;
        for (let i = 0; i < displayLines.length; i++) {
            ctx.fillText(displayLines[i].trim(), 600, titleStartY + (i * 68));
        }

        // Venue & Date Info
        const venueText = '📍 ' + (venue || 'Photomate Booth') + (date ? '   •   🗓️ ' + date : '');
        ctx.fillStyle = '#64748B';
        ctx.font = '600 30px system-ui, -apple-system, sans-serif';
        const venueY = titleStartY + (displayLines.length * 68) + 20;
        ctx.fillText(venueText, 600, venueY);
        ctx.restore();

        // 5. Minimalist Call-to-Action Pill
        const ctaY = venueY + 75;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = '#0F172A'; // Sleek Dark Charcoal Pill
        roundRect(220, ctaY - 34, 760, 68, 34);
        ctx.fill();

        ctx.fillStyle = '#FFFFFF';
        ctx.font = 'bold 26px system-ui, -apple-system, sans-serif';
        ctx.fillText('SILAKAN SCAN UNTUK MENGAMBIL ANTREAN', 600, ctaY + 8);
        ctx.restore();

        // 6. QR Code Card (Clean, High Contrast, Minimalist)
        const cardY = ctaY + 70;
        const cardW = 780;
        const cardH = 800;
        const cardX = (width - cardW) / 2;

        ctx.save();
        ctx.shadowColor = 'rgba(0, 0, 0, 0.07)';
        ctx.shadowBlur = 35;
        ctx.shadowOffsetY = 15;
        ctx.fillStyle = '#FFFFFF';
        roundRect(cardX, cardY, cardW, cardH, 36);
        ctx.fill();
        ctx.restore();

        // Thin elegant border around QR card
        ctx.save();
        ctx.strokeStyle = '#E2E8F0';
        ctx.lineWidth = 2;
        roundRect(cardX, cardY, cardW, cardH, 36);
        ctx.stroke();
        ctx.restore();

        // 7. Load & Draw QR Code Image
        const qrSourceUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' + encodeURIComponent(url);
        
        const qrImage = new Image();
        qrImage.crossOrigin = 'anonymous';

        await new Promise((resolve, reject) => {
            qrImage.onload = resolve;
            qrImage.onerror = () => {
                fetch(qrSourceUrl)
                    .then(r => r.blob())
                    .then(b => {
                        const objUrl = URL.createObjectURL(b);
                        qrImage.onload = () => {
                            URL.revokeObjectURL(objUrl);
                            resolve();
                        };
                        qrImage.onerror = reject;
                        qrImage.src = objUrl;
                    })
                    .catch(reject);
            };
            qrImage.src = qrSourceUrl;
        });

        const qrSize = 600;
        const qrX = (width - qrSize) / 2;
        const qrY = cardY + 45;
        ctx.drawImage(qrImage, qrX, qrY, qrSize, qrSize);

        // URL & Event Code text inside QR card
        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = '#2563EB'; // Clean modern blue link
        ctx.font = 'bold 26px system-ui, -apple-system, sans-serif';
        ctx.fillText(url.replace(/^https?:\/\//, ''), 600, cardY + cardH - 62);

        ctx.fillStyle = '#64748B';
        ctx.font = '600 20px system-ui, -apple-system, sans-serif';
        ctx.fillText('Kode Event: ' + code, 600, cardY + cardH - 26);
        ctx.restore();

        // 8. Footer (Minimalist & Clean)
        ctx.save();
        ctx.fillStyle = '#94A3B8';
        ctx.font = '500 22px system-ui, -apple-system, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Photomate • Self-Photo Booth & Event Solution • www.photomate.id', 600, height - 70);
        ctx.restore();

        // 9. Trigger PNG Download
        const filename = 'Poster-Antrean-' + code + '-1200x1800.png';
        
        if (canvas.toBlob) {
            canvas.toBlob((blob) => {
                if (blob) {
                    const blobUrl = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = blobUrl;
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
                } else {
                    const dataUrl = canvas.toDataURL('image/png');
                    const link = document.createElement('a');
                    link.href = dataUrl;
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
            }, 'image/png');
        } else {
            const dataUrl = canvas.toDataURL('image/png');
            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

    } catch (err) {
        console.error('Download error:', err);
        alert('Terjadi kendala saat membuat poster: ' + err.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
};

window.downloadRawQrCode = function (url, eventCode) {
    const rawUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' + encodeURIComponent(url);
    fetch(rawUrl)
        .then(res => res.blob())
        .then(blob => {
            const blobUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = 'QR-Antrean-' + eventCode + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
        })
        .catch(() => {
            window.open(rawUrl, '_blank');
        });
};
