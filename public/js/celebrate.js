(function () {
    var audioCtx = null;

    window.unlockCelebration = function () {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
    };

    function donDon() {
        if (!audioCtx) {
            return;
        }
        [174.61, 130.81].forEach(function (freq, index) {
            var start = audioCtx.currentTime + index * 0.42;
            var osc = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(freq, start);
            osc.frequency.exponentialRampToValueAtTime(freq * 0.5, start + 0.48);
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.42, start + 0.03);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.55);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(start);
            osc.stop(start + 0.6);
        });
    }

    window.celebrate = function (options) {
        options = options || {};
        var overlay = document.getElementById('celebrate-overlay');
        var canvas = document.getElementById('celebrate-canvas');
        if (!overlay || !canvas) {
            if (options.redirect) {
                window.location = options.redirect;
            }
            return;
        }

        document.getElementById('celebrate-title').textContent = options.title || 'That file is in Odoo';
        document.getElementById('celebrate-copy').textContent = options.copy || 'Back to the checklist. The next Start button is lit.';
        overlay.hidden = false;
        donDon();

        var ctx = canvas.getContext('2d');
        var particles = [];
        var colors = ['#f2c14e', '#e25c4a', '#2f7d6d', '#7eb6d9', '#ffffff', '#14324f'];
        var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var count = reduced ? 24 : 110;

        function resize() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        resize();

        function spray(side) {
            var originX = side === 'left' ? canvas.width * 0.08 : canvas.width * 0.92;
            var originY = canvas.height * 0.9;
            for (var i = 0; i < count; i++) {
                var deg = side === 'left' ? -85 + Math.random() * 55 : -150 + Math.random() * 55;
                var rad = deg * Math.PI / 180;
                var speed = 7 + Math.random() * 9;
                particles.push({
                    x: originX,
                    y: originY,
                    vx: Math.cos(rad) * speed,
                    vy: Math.sin(rad) * speed,
                    w: 6 + Math.random() * 7,
                    h: 4 + Math.random() * 6,
                    color: colors[i % colors.length],
                    life: 80 + Math.random() * 30,
                    rot: Math.random() * 6,
                    spin: (Math.random() - 0.5) * 0.3
                });
            }
        }
        spray('left');
        spray('right');

        function frame() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(function (p) {
                p.life -= 1;
                p.vy += 0.16;
                p.x += p.vx;
                p.y += p.vy;
                p.rot += p.spin;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.fillStyle = p.color;
                ctx.globalAlpha = Math.max(p.life / 100, 0);
                ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
                ctx.restore();
            });
            particles = particles.filter(function (p) { return p.life > 0; });
            if (particles.length) {
                requestAnimationFrame(frame);
            }
        }
        requestAnimationFrame(frame);

        if (options.redirect) {
            window.setTimeout(function () {
                window.location = options.redirect;
            }, reduced ? 900 : 2600);
        } else {
            window.setTimeout(function () {
                overlay.hidden = true;
            }, reduced ? 900 : 2600);
        }
    };
})();
