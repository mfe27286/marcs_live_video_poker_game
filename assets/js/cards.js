// Playing card renderer
const SUIT = { s: { sym: "♠", color: "#0B0E14" }, c: { sym: "♣", color: "#0B0E14" }, h: { sym: "♥", color: "#DC2626" }, d: { sym: "♦", color: "#DC2626" } };

function cardHtml(card, size = "md", index = 0, dealt = true) {
  const delay = `style="animation-delay:${index * 90}ms"`;
  if (!card) return `<div class="card-back ${size}" ${delay} data-testid="card-back"></div>`;
  const rank = card[0] === "T" ? "10" : card[0];
  const suit = SUIT[card[1]];
  return `<div class="card ${size} ${dealt ? "dealt" : ""}" ${delay} data-testid="card-${card}">
    <span style="color:${suit.color}">${rank}</span>
    <span class="mid" style="color:${suit.color}">${suit.sym}</span>
    <span class="bot" style="color:${suit.color}">${rank}</span>
  </div>`;
}
