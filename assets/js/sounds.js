// WebAudio sound effects (no files needed)
const Sounds = {
  ctx: null,
  enabled: localStorage.getItem("rh_sound") !== "off",
  setEnabled(v) { this.enabled = v; localStorage.setItem("rh_sound", v ? "on" : "off"); },
  play(kind) {
    if (!this.enabled) return;
    try {
      if (!this.ctx) this.ctx = new (window.AudioContext || window.webkitAudioContext)();
      const ctx = this.ctx;
      if (ctx.state === "suspended") ctx.resume();
      const t0 = ctx.currentTime;
      const blip = (type, f1, f2, dur, gain) => {
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.type = type;
        o.frequency.setValueAtTime(f1, t0);
        o.frequency.exponentialRampToValueAtTime(f2, t0 + dur);
        g.gain.setValueAtTime(0.0001, t0);
        g.gain.exponentialRampToValueAtTime(gain, t0 + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur + 0.02);
        o.connect(g).connect(ctx.destination);
        o.start(t0); o.stop(t0 + dur + 0.03);
      };
      if (kind === "deal") blip("triangle", 880, 320, 0.1, 0.18);
      else if (kind === "pop") blip("sine", 520, 760, 0.14, 0.14);
      else if (kind === "chip") { blip("square", 2100, 1500, 0.05, 0.06); setTimeout(() => this.play("chip2"), 45); }
      else if (kind === "chip2") blip("square", 2400, 1700, 0.05, 0.05);
      else if (kind === "win") [523, 659, 784].forEach((f, i) => setTimeout(() => { try { blip("triangle", f, f, 0.28, 0.12); } catch (e) {} }, i * 70));
      else if (kind === "fanfare") {
        // rising fanfare + chord + chip shower
        [[523, 0], [659, 110], [784, 220], [1047, 330]].forEach(([f, d]) => setTimeout(() => { try { blip("sawtooth", f, f, 0.22, 0.07); blip("triangle", f, f, 0.3, 0.1); } catch (e) {} }, d));
        setTimeout(() => { try { [523, 659, 784, 1047].forEach((f) => blip("triangle", f, f, 0.9, 0.08)); } catch (e) {} }, 480);
        for (let i = 0; i < 10; i++) setTimeout(() => this.play("chip"), 600 + i * 90);
      }
    } catch (e) { /* audio not available */ }
  },
};
