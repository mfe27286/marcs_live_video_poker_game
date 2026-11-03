import json, time, urllib.request, http.cookiejar, sys
sys.path.insert(0, __file__.rsplit('/', 1)[0])
from api_smoke import Client, BASE

def reg(name):
    c = Client(); ts = time.time_ns()
    r = c.call("/api/auth.php?action=register", {"email": f"{name.lower()}{ts}@t.com", "password": "secret123", "name": name})
    c.csrf = r["csrf"]; c.id = r["user"]["id"]; return c

def wallet(c): return c.call("/api/auth.php?action=me")["user"]["chips"]
def poll(c, code): return c.call(f"/api/game.php?action=poll&code={code}&v=0&ev=-1")

def play_until_showdown(players, code, strategy="call"):
    for _ in range(40):
        st = poll(players[0], code)["state"]
        if st["phase"] in ("showdown", "waiting"): return st
        actor = next(p for p in players if p.id == next(x["user_id"] for x in st["players"] if x["seat"] == st["to_act"]))
        la = poll(actor, code)["state"]["legal_actions"]
        if strategy == "allin" and la.get("raise"):
            r = actor.call(f"/api/game.php?action=act&code={code}", {"action_type": "raise", "amount": la["raise"]["max"]})
        else:
            r = actor.call(f"/api/game.php?action=act&code={code}", {"action_type": "check" if la.get("check") else "call"})
        assert r.get("ok"), r
    raise AssertionError("hand did not finish")

# ---------- cash game: buy-in from wallet, stack returned on leave ----------
a, b = reg("CashA"), reg("CashB")
w0 = wallet(a); assert w0 == 10000, w0
r = a.call("/api/rooms.php?action=create", {"name": "Cash", "game_mode": "cash", "min_buyin": 500, "max_buyin": 2000, "max_seats": 4, "turn_seconds": 20})
code = r["code"]
r = a.call(f"/api/game.php?action=sit&code={code}", {"seat": 0, "amount": 300}); assert r.get("_status") == 400 and "between" in r["error"], r
r = a.call(f"/api/game.php?action=sit&code={code}", {"seat": 0, "amount": 1500}); assert r["ok"], r
assert r["wallet"] == 8500 and r["state"]["players"][0]["stack"] == 1500, r["wallet"]
r = b.call(f"/api/game.php?action=sit&code={code}", {"seat": 9, "amount": 1000}); assert r.get("_status") == 400, r  # beyond max_seats
r = b.call(f"/api/game.php?action=sit&code={code}", {"seat": 1, "amount": 1000}); assert r["ok"]
assert r["state"]["max_seats"] == 4 and r["state"]["min_buyin"] == 500
print("cash: auto-start countdown ->", poll(a, code)["state"]["next_hand_at"] is not None)
time.sleep(4.6); st = poll(a, code)["state"]; assert st["phase"] == "preflop", st["phase"]
st = play_until_showdown([a, b], code)
stacks = {p["user_id"]: p["stack"] for p in st["players"]}
print("cash hand done, stacks", stacks, "winner", [w["name"] for w in st["last_winners"]])
# add chips (top-up) between hands
r = a.call(f"/api/game.php?action=rebuy&code={code}", {"amount": 5000})
me = next(p for p in r["state"]["players"] if p["user_id"] == a.id); assert me["stack"] == 2000, me["stack"]  # capped at max buy-in
r = a.call(f"/api/game.php?action=leave&code={code}", {})
wa = wallet(a); print("cash: A wallet after leave", wa); assert wa == 10000 - 1500 - (2000 - stacks[a.id]) + 2000, wa
r = b.call(f"/api/game.php?action=leave&code={code}", {}); wb = wallet(b); assert wa + wb == 20000, (wa, wb)
print("cash OK (chips conserved)")

# ---------- tournament: buy-in, full-table autostart, host start, elimination, payout ----------
h, p2, p3 = reg("TourH"), reg("TourB"), reg("TourC")
r = h.call("/api/rooms.php?action=create", {"name": "Tourney", "game_mode": "tournament", "buy_in": 500, "starting_stack": 1000, "max_seats": 3, "payout": "top3", "allow_rebuys": False, "turn_seconds": 20, "small_blind": 50, "big_blind": 100})
code = r["code"]
h.call(f"/api/game.php?action=sit&code={code}", {"seat": 0, "amount": 0}); p2.call(f"/api/game.php?action=sit&code={code}", {"seat": 1, "amount": 0})
st = poll(h, code)["state"]; assert st["tournament"]["prize_pool"] == 1000 and st["next_hand_at"] is None, "must not auto-start before full"
assert wallet(h) == 9500
r = p2.call(f"/api/game.php?action=start&code={code}", {}); assert r.get("_status") == 400
p3.call(f"/api/game.php?action=sit&code={code}", {"seat": 2, "amount": 0})
st = poll(h, code)["state"]; assert st["next_hand_at"] is not None, "full table should schedule start"
print("tournament: full -> countdown scheduled; prize pool", st["tournament"]["prize_pool"])
time.sleep(4.6); st = poll(h, code)["state"]; assert st["phase"] == "preflop" and st["tournament"]["started"]
r = reg("Late").call(f"/api/game.php?action=sit&code={code}", {"seat": 1, "amount": 0}); assert r.get("_status") in (400,), r
# all-in every hand until the tournament finishes
for hand in range(12):
    st = play_until_showdown([h, p2, p3], code, "allin")
    t = st["tournament"]
    if t["finished"]: break
    time.sleep(7.6); poll(h, code)
    st = poll(h, code)["state"]
    if st["tournament"]["finished"]: break
st = poll(h, code)["state"]; t = st["tournament"]
assert t["finished"], t
print("tournament finished:", [(r["place"], r["name"], r["amount"]) for r in t["results"]])
total = sum(r["amount"] for r in t["results"]); assert total == 1500, total
ws = [wallet(c) for c in (h, p2, p3)]; assert sum(ws) == 30000, ws
print("tournament OK (prize pool fully paid, chips conserved)", ws)

# ---------- daily bonus ----------
r = h.call("/api/auth.php?action=claim_bonus", {}); assert r.get("_status") == 400, r
print("bonus refused when rich:", r["error"][:50])
print("ALL OK")

# ---------- leaving an unstarted tournament refunds the entry fee ----------
x, y = reg("RefA"), reg("RefB")
code = x.call("/api/rooms.php?action=create", {"name": "Ref", "game_mode": "tournament", "buy_in": 1000, "max_seats": 5})["code"]
x.call(f"/api/game.php?action=sit&code={code}", {"seat": 0}); assert wallet(x) == 9000
x.call(f"/api/game.php?action=leave&code={code}", {}); assert wallet(x) == 10000, wallet(x)
st = poll(y, code)["state"]; assert st["tournament"]["prize_pool"] == 0 and st["tournament"]["entrants"] == 0
print("refund OK")
