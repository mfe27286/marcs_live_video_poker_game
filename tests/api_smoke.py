import json, sys, time, urllib.request, http.cookiejar

BASE = "http://localhost:8085"

class Client:
    def __init__(self):
        self.cj = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj))
        self.csrf = None
    def call(self, path, body=None):
        req = urllib.request.Request(BASE + path, method="POST" if body is not None else "GET")
        req.add_header("Content-Type", "application/json")
        if self.csrf: req.add_header("X-CSRF-Token", self.csrf)
        data = json.dumps(body).encode() if body is not None else None
        try:
            with self.op.open(req, data) as r: return json.loads(r.read())
        except urllib.error.HTTPError as e:
            return {"_status": e.code, **json.loads(e.read() or b"{}")}

def main():
    a, b = Client(), Client()
    ts = int(time.time())
    for i, c in enumerate([a, b]):
        r = c.call("/api/auth.php?action=register", {"email": f"p{i}_{ts}@t.com", "password": "secret123", "name": f"Player{i}"})
        assert "user" in r, r; c.csrf = r["csrf"]; c.id = r["user"]["id"]
    r = a.call("/api/rooms.php?action=create", {"name": "Test", "small_blind": 10, "big_blind": 20, "starting_stack": 1000, "game_mode": "tournament", "allow_rebuys": True, "turn_seconds": 5})
    code = r["code"]; print("room", code)
    assert b.call(f"/api/rooms.php?action=info&code={code}")["code"] == code
    print("sit a", a.call(f"/api/game.php?action=sit&code={code}", {"seat": 0})["state"]["players"][0]["name"])
    print("sit b", len(b.call(f"/api/game.php?action=sit&code={code}", {"seat": 3})["state"]["players"]))
    r = b.call(f"/api/game.php?action=start&code={code}", {}); assert r.get("_status") == 400, r
    r = a.call(f"/api/game.php?action=start&code={code}", {}); st = r["state"]
    print("phase", st["phase"], "to_act", st["to_act"], "deadline", st["turn_deadline"] is not None, "hole", st["players"][0]["hole"], st["players"][1]["hole"])
    # play a hand: whoever is to act calls/checks until showdown
    for _ in range(20):
        sa = a.call(f"/api/game.php?action=poll&code={code}&v=0&ev=-1")["state"]
        if sa["phase"] == "showdown": break
        actor = a if sa["to_act"] == 0 else b
        la = actor.call(f"/api/game.php?action=poll&code={code}&v=0&ev=-1")["state"]["legal_actions"]
        assert la, ("no legal", sa["to_act"])
        act = "check" if la.get("check") else "call"
        r = actor.call(f"/api/game.php?action=act&code={code}", {"action_type": act}); assert r.get("ok"), r
        print(" ", act, "->", r["state"]["phase"], r["state"]["community"])
    sa = a.call(f"/api/game.php?action=poll&code={code}&v=0&ev=-1")["state"]
    print("winners", sa["last_winners"], "history", len(sa["history"]))
    me = a.call("/api/auth.php?action=me")["user"]["stats"]; print("stats a", me)
    # timeout test: start new hand, wait > 5s, poll -> auto action
    r = a.call(f"/api/game.php?action=start&code={code}", {}); print("hand2 to_act", r["state"]["to_act"])
    time.sleep(6)
    sa = a.call(f"/api/game.php?action=poll&code={code}&v=0&ev=-1")["state"]
    print("after timeout: log", sa["log"][-2:], "phase", sa["phase"])
    # chat/reaction/signal
    cur = a.call(f"/api/game.php?action=poll&code={code}&v=0&ev=-1")["event_cursor"]
    b.call(f"/api/game.php?action=chat&code={code}", {"text": "hello"}); b.call(f"/api/game.php?action=reaction&code={code}", {"emoji": "🔥"})
    b.call(f"/api/game.php?action=signal&code={code}", {"type": "rtc_offer", "to": a.id, "data": {"sdp": "x", "type": "offer"}})
    r = a.call(f"/api/game.php?action=poll&code={code}&v=0&ev={cur}")
    print("events", [(e["type"], e["payload"]) for e in r["events"]], "signals", r["signals"], "peers", [p["name"] for p in r["peers"]])
    r = a.call(f"/api/game.php?action=poll&code={code}&v=0&ev={cur}"); assert r["signals"] == [], "signals should be consumed"
    # leave
    r = b.call(f"/api/game.php?action=leave&code={code}", {}); print("after leave players", len(r["state"]["players"]))
    print("ALL OK")

if __name__ == "__main__":
    main()
