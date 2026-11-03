// WebRTC mesh: one RTCPeerConnection per other player, using the "perfect negotiation" pattern.
// Signaling goes through the PHP API (polled). Works even if one side has no camera.
class PeerMesh {
  constructor({ myId, iceServers, sendSignal, onStream, onStreamGone, onStateChange }) {
    this.myId = Number(myId);
    this.ice = { iceServers };
    this.sendSignal = sendSignal;
    this.onStream = onStream;
    this.onStreamGone = onStreamGone;
    this.onStateChange = onStateChange || (() => {});
    this.peers = {}; // pid -> { pc, polite, makingOffer, ignoreOffer, pendingIce: [] }
    this.localStream = null;
  }

  // Attach (or later swap in) the local camera/mic and renegotiate with everyone
  setLocalStream(stream) {
    this.localStream = stream;
    Object.values(this.peers).forEach(({ pc }) => this.attachLocalTracks(pc));
  }

  attachLocalTracks(pc) {
    if (!this.localStream) {
      if (pc.getTransceivers().length === 0) { pc.addTransceiver("audio", { direction: "recvonly" }); pc.addTransceiver("video", { direction: "recvonly" }); }
      return;
    }
    this.localStream.getTracks().forEach((track) => {
      const existing = pc.getTransceivers().find((t) => t.receiver.track && t.receiver.track.kind === track.kind && !t.sender.track);
      if (existing) { existing.direction = "sendrecv"; existing.sender.replaceTrack(track); }
      else if (!pc.getSenders().some((s) => s.track === track)) pc.addTrack(track, this.localStream);
    });
  }

  getPeer(pid) {
    pid = Number(pid);
    if (this.peers[pid]) return this.peers[pid];
    const pc = new RTCPeerConnection(this.ice);
    const peer = { pc, polite: this.myId > pid, makingOffer: false, ignoreOffer: false, pendingIce: [] };
    this.peers[pid] = peer;
    pc.ontrack = (e) => { const stream = e.streams[0] || new MediaStream([e.track]); this.onStream(pid, stream); };
    pc.onicecandidate = (e) => { if (e.candidate) this.sendSignal("rtc_ice", pid, e.candidate.toJSON()); };
    pc.onnegotiationneeded = async () => {
      try {
        peer.makingOffer = true;
        await pc.setLocalDescription();
        this.sendSignal("rtc_offer", pid, pc.localDescription.toJSON());
      } catch (err) { console.debug("offer failed", pid, err); }
      finally { peer.makingOffer = false; }
    };
    pc.oniceconnectionstatechange = () => {
      this.onStateChange(pid, pc.iceConnectionState);
      if (pc.iceConnectionState === "failed") { try { pc.restartIce(); } catch (e) { this.closePeer(pid); } }
    };
    pc.onconnectionstatechange = () => { if (pc.connectionState === "closed") this.closePeer(pid); };
    this.attachLocalTracks(pc);
    return peer;
  }

  closePeer(pid) {
    const peer = this.peers[pid];
    if (!peer) return;
    try { peer.pc.close(); } catch (e) {}
    delete this.peers[pid];
    this.onStreamGone(pid);
  }

  // Called with the current list of online peers on every poll
  syncPeers(peerIds) {
    const ids = peerIds.map(Number).filter((id) => id !== this.myId);
    ids.forEach((pid) => this.getPeer(pid));
    Object.keys(this.peers).forEach((pid) => { if (!ids.includes(Number(pid))) this.closePeer(pid); });
  }

  async handleSignal(msg) {
    const pid = Number(msg.from);
    if (pid === this.myId) return;
    const peer = this.getPeer(pid);
    const { pc } = peer;
    try {
      if (msg.type === "rtc_offer" || msg.type === "rtc_answer") {
        const desc = msg.data;
        if (desc.type === "offer") {
          const collision = peer.makingOffer || pc.signalingState !== "stable";
          peer.ignoreOffer = !peer.polite && collision;
          if (peer.ignoreOffer) return;
          await pc.setRemoteDescription(desc);
          await pc.setLocalDescription();
          this.sendSignal("rtc_answer", pid, pc.localDescription.toJSON());
        } else if (pc.signalingState === "have-local-offer") {
          await pc.setRemoteDescription(desc);
        }
        await this.flushIce(peer);
      } else if (msg.type === "rtc_ice" && msg.data) {
        if (pc.remoteDescription) {
          try { await pc.addIceCandidate(msg.data); } catch (err) { if (!peer.ignoreOffer) throw err; }
        } else peer.pendingIce.push(msg.data);
      }
    } catch (err) { console.debug("signal error", msg.type, pid, err); }
  }

  async flushIce(peer) {
    const queue = peer.pendingIce; peer.pendingIce = [];
    for (const c of queue) { try { await peer.pc.addIceCandidate(c); } catch (e) {} }
  }

  closeAll() {
    Object.keys(this.peers).forEach((pid) => { try { this.peers[pid].pc.close(); } catch (e) {} });
    this.peers = {};
    if (this.localStream) this.localStream.getTracks().forEach((t) => t.stop());
  }
}
