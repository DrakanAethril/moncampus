/**
 * The live contest's little celebrations: confetti on the podium and on a right answer, a few
 * synthesised notes on the room's screen, a number that counts up. Each is a comfort - refused,
 * reduced or unsupported, nothing else changes.
 */

function reducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

/** Confetti over `host`, coloured by the theme's own tokens, shaped by its catalogue entry. */
export function confetti(host, style, count = 160) {
    if (!host || !host.isConnected || reducedMotion()) {
        return;
    }
    const canvas = document.createElement('canvas');
    canvas.className = 'ql-confetti';
    host.appendChild(canvas);
    const width = canvas.width = host.clientWidth;
    const height = canvas.height = host.clientHeight;
    const context = canvas.getContext('2d');
    const css = getComputedStyle(host);
    const colours = ['--c1', '--c2', '--c3', '--c4', '--c5', '--accent'].map((name) => css.getPropertyValue(name).trim() || '#f0a');
    const scale = width / 500 + 0.4;
    const pieces = Array.from({ length: count }, () => ({
        x: width / 2 + (Math.random() - 0.5) * width * 0.4,
        y: height * 0.45,
        vx: (Math.random() - 0.5) * 14 * (width / 400),
        vy: (-4 - Math.random() * 10) * (height / 700),
        r: Math.random() * 6.3,
        vr: (Math.random() - 0.5) * 0.6,
        s: (6 + Math.random() * 7) * scale,
        c: colours[Math.floor(Math.random() * colours.length)],
    }));
    const start = performance.now();
    const frame = (now) => {
        context.clearRect(0, 0, width, height);
        pieces.forEach((p) => {
            p.vy += 0.32 * (height / 700);
            p.x += p.vx;
            p.y += p.vy;
            p.r += p.vr;
            p.vx *= 0.99;
            context.save();
            context.translate(p.x, p.y);
            context.rotate(p.r);
            context.fillStyle = p.c;
            context.strokeStyle = '#111';
            context.lineWidth = 2;
            switch (style) {
                case 'glow':
                    context.shadowColor = p.c;
                    context.shadowBlur = 12;
                    context.fillRect(-p.s / 2, -1.5, p.s, 3);
                    break;
                case 'circle':
                    context.beginPath();
                    context.arc(0, 0, p.s / 2.2, 0, 7);
                    context.fill();
                    break;
                case 'star':
                    context.beginPath();
                    for (let i = 0; i < 10; i += 1) {
                        const angle = (i * Math.PI) / 5;
                        const radius = i % 2 ? p.s / 4 : p.s / 1.7;
                        context.lineTo(Math.cos(angle) * radius, Math.sin(angle) * radius);
                    }
                    context.closePath();
                    context.fill();
                    context.stroke();
                    break;
                case 'strip':
                    context.fillStyle = Math.random() < 0.5 ? '#F7C548' : '#FFF4DA';
                    context.fillRect(-p.s / 2, -p.s / 6, p.s, p.s / 3);
                    break;
                case 'fish':
                    context.beginPath();
                    context.ellipse(0, 0, p.s / 1.6, p.s / 3, 0, 0, 7);
                    context.moveTo(p.s / 1.7, 0);
                    context.lineTo(p.s, -p.s / 3);
                    context.lineTo(p.s, p.s / 3);
                    context.closePath();
                    context.fill();
                    break;
                default:
                    context.fillRect(-p.s / 2, -p.s / 3, p.s, p.s / 1.5);
                    context.strokeRect(-p.s / 2, -p.s / 3, p.s, p.s / 1.5);
            }
            context.restore();
        });
        if (now - start < 4200 && canvas.isConnected) {
            requestAnimationFrame(frame);
        } else {
            canvas.remove();
        }
    };
    requestAnimationFrame(frame);
}

const TUNES = {
    tick: [[660, 0.07]],
    go: [[880, 0.1], [1320, 0.2]],
    join: [[740, 0.05], [990, 0.05]],
    reveal: [[523, 0.09], [659, 0.09], [784, 0.2]],
    fanfare: [[523, 0.12], [659, 0.12], [784, 0.12], [1047, 0.4]],
};
let audio = null;

/** A few notes in the theme's voice - only after a click allowed sound (the browser's rule). */
export function play(tune, wave) {
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        let time = audio.currentTime;
        (TUNES[tune] || []).forEach(([frequency, duration]) => {
            const oscillator = audio.createOscillator();
            const gain = audio.createGain();
            oscillator.type = wave || 'triangle';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, time);
            gain.gain.exponentialRampToValueAtTime(0.16, time + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, time + duration);
            oscillator.connect(gain);
            gain.connect(audio.destination);
            oscillator.start(time);
            oscillator.stop(time + duration + 0.03);
            time += duration * 0.92;
        });
    } catch {
        // No sound, nothing else changes.
    }
}

/** Every [data-count] of `root` counts up to its value; `data-from` starts it elsewhere than 0. */
export function countUp(root) {
    root.querySelectorAll('[data-count]').forEach((element) => {
        const to = Number(element.dataset.count);
        const from = undefined !== element.dataset.from ? Number(element.dataset.from) : 0;
        const plus = undefined === element.dataset.from;
        const start = performance.now();
        const step = (now) => {
            const k = Math.min(1, (now - start) / 900);
            const value = from + (to - from) * (1 - (1 - k) ** 3);
            element.textContent = `${plus ? '+' : ''}${Math.round(value).toLocaleString('fr-FR')}`;
            if (k < 1) {
                requestAnimationFrame(step);
            }
        };
        requestAnimationFrame(step);
    });
}
