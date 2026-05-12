(function () {
  if (!window.L || !window.SESAME_CAMERAS) return;

  const cameras = window.SESAME_CAMERAS || [];
  const first = cameras.find((camera) => Number.isFinite(camera.lat) && Number.isFinite(camera.lng));
  const map = L.map("map", { zoomControl: true });
  map.setView(first ? [first.lat, first.lng] : [25.2048, 55.2708], first ? 14 : 10);

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap"
  }).addTo(map);

  cameras.forEach((camera) => {
    const icon = L.divIcon({
      className: "",
      html: `<div class="camera-marker" style="transform: rotate(${Number(camera.direction) || 0}deg)"><span style="transform: rotate(${-1 * (Number(camera.direction) || 0)}deg)">●</span></div>`,
      iconSize: [28, 28],
      iconAnchor: [14, 14]
    });

    const marker = L.marker([camera.lat, camera.lng], { icon }).addTo(map);
    marker.bindPopup(`
      <strong>${escapeHtml(camera.name)}</strong><br>
      <a href="${camera.player}">Открыть видео</a>
    `);
  });

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }
})();
