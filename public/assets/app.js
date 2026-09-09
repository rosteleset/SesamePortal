(function () {
  const messages = window.SESAME_I18N || {};

  const YANDEX_PROJECTION_K = 0.001064;
  const YANDEX_PROJECTION_R = 6378137;
  const YANDEX_PROJECTION_MAX_LAT = 85.0511287798;
  let yandexCrs = null;

  function yandexWorldY(lat) {
    const phi = lat * Math.PI / 180;
    const sin = Math.sin(phi);
    return YANDEX_PROJECTION_R * Math.log((1 + sin) / (1 - sin)) / 2
      - 2 * Math.PI * YANDEX_PROJECTION_R * YANDEX_PROJECTION_K * sin;
  }

  function yandexProjection() {
    const R = YANDEX_PROJECTION_R;
    const half = Math.PI * R;
    return {
      project(latlng) {
        const d = Math.PI / 180;
        const lat = Math.max(Math.min(YANDEX_PROJECTION_MAX_LAT, latlng.lat), -YANDEX_PROJECTION_MAX_LAT);
        return L.point(
          R * latlng.lng * d,
          yandexWorldY(lat)
        );
      },
      unproject(point) {
        const d = 180 / Math.PI;
        let phi = 2 * Math.atan(Math.exp(point.y / R)) - Math.PI / 2;
        for (let i = 0; i < 8; i++) {
          const sin = Math.sin(phi);
          const cos = Math.cos(phi);
          const y = R * Math.log((1 + sin) / (1 - sin)) / 2
            - 2 * Math.PI * R * YANDEX_PROJECTION_K * sin;
          const dy = y - point.y;
          if (Math.abs(dy) < 1e-4) break;
          phi -= dy / (R / cos - 2 * Math.PI * R * YANDEX_PROJECTION_K * cos);
        }
        return L.latLng(phi * d, point.x * d / R);
      },
      bounds: L.bounds([-half, -half], [half, half])
    };
  }

  function yandexCRS() {
    const scale = 0.5 / (Math.PI * YANDEX_PROJECTION_R);
    return L.Util.extend({}, L.CRS.Earth, {
      code: 'EPSG:Yandex3857',
      projection: yandexProjection(),
      transformation: L.transformation(scale, 0.5, -scale, 0.5)
    });
  }

  function mapCRS() {
    if ((window.SESAME_MAP_PROVIDER || 'openstreetmap') !== 'yandex') return L.CRS.EPSG3857;
    if (!yandexCrs) yandexCrs = yandexCRS();
    return yandexCrs;
  }

  function tileLayerOptions() {
    const provider = window.SESAME_MAP_PROVIDER || 'openstreetmap';
    return {
      maxZoom: 19,
      attribution: provider === 'openstreetmap' ? '&copy; OpenStreetMap' : '&copy; <a href="https://yandex.ru/maps/">Яндекс Карты</a>'
    };
  }
  function tileLayerUrl() {
    const provider = window.SESAME_MAP_PROVIDER || 'openstreetmap';
    switch (provider) {
      case 'yandex': return 'https://core-renderer-tiles.maps.yandex.net/tiles?l=map&x={x}&y={y}&z={z}&lang=ru_RU';
      default: return 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    }
  }
  function mapDefaultView() {
    const view = window.SESAME_MAP_VIEW || {};
    return {
      lat: Number.isFinite(Number(view.lat)) ? Number(view.lat) : 47.242057,
      lng: Number.isFinite(Number(view.lng)) ? Number(view.lng) : 38.889615
    };
  }
  initMap();
  initCameraPositionEditor();
  initPlayer();
  initPreviewRefresh();
  initDensitySwitch();
  initGroupTreePickers();
  initCameraFormVisibility();
  initDvrStreamOptions();
  initSubmitProgress();
  initConfirmDialogs();
  initAssignmentPickers();
  initDvrStreamImport();
  initLocalTimes();
  initPlayerBackBridge();
  initThemeToggle();
  initStaticTokens();
  initInstallPrompt();
  initNavToggle();
  initCallbackLogin();
  initUserMenu();

  function initUserMenu() {
    document.addEventListener("click", function (e) {
      var trigger = e.target.closest("[data-user-menu-toggle]");
      if (trigger) {
        e.stopPropagation();
        var dropdown = trigger.closest(".user-dropdown");
        if (!dropdown) return;
        var wasOpen = dropdown.classList.contains("open");
        document.querySelectorAll(".user-dropdown.open").forEach(function (d) {
          d.classList.remove("open");
          var m = d.querySelector(".user-menu");
          if (m) { m.style.left = ''; m.style.top = ''; m.style.transform = ''; }
        });
        if (!wasOpen) {
          dropdown.classList.add("open");
          var menu = dropdown.querySelector(".user-menu");
          if (menu) {
            var r = trigger.getBoundingClientRect();
            menu.style.left = Math.max(8, r.right - 200) + "px";
            if (r.bottom + 140 > window.innerHeight) {
              menu.style.top = (r.top - 2) + "px";
              menu.style.transform = "translateY(-100%)";
            } else {
              menu.style.top = (r.bottom + 2) + "px";
              menu.style.transform = "";
            }
          }
        }
        return;
      }
      if (!e.target.closest(".user-menu")) {
        document.querySelectorAll(".user-dropdown.open").forEach(function (d) { d.classList.remove("open"); });
      }
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        document.querySelectorAll(".user-dropdown.open").forEach(function (d) { d.classList.remove("open"); });
      }
    });
  }

  function initThemeToggle() {
    const button = document.querySelector("[data-theme-toggle]");
    if (!button) return;

    const root = document.documentElement;
    const media = window.matchMedia ? matchMedia("(prefers-color-scheme: dark)") : null;

    function render(theme) {
      button.title = theme === "dark" ? (button.dataset.titleLight || "") : (button.dataset.titleDark || "");
      button.setAttribute("aria-label", button.title);
      button.innerHTML = theme === "dark"
        ? '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z"/></svg>';
    }

    function currentTheme() {
      return root.dataset.theme === "dark" ? "dark" : "light";
    }

    function apply(theme) {
      root.dataset.theme = theme;
      render(theme);
    }

    button.addEventListener("click", () => {
      const next = currentTheme() === "dark" ? "light" : "dark";
      apply(next);
      root.removeAttribute("data-theme-auto");
      fetch("/theme", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ theme: next, csrf: window.SESAME_CSRF || "" })
      }).catch(() => {
        apply(next === "dark" ? "light" : "dark");
      });
    });

    if (media && media.addEventListener) {
      media.addEventListener("change", (event) => {
        if (root.dataset.themeAuto === "1") {
          apply(event.matches ? "dark" : "light");
        }
      });
    }

    render(currentTheme());
  }

  function initMap() {
    if (!window.L || !window.SESAME_CAMERAS || !document.getElementById("map")) return;

    const cameras = window.SESAME_CAMERAS || [];
    const visibleCameras = cameras.filter((camera) => Number.isFinite(camera.lat) && Number.isFinite(camera.lng));
    const map = L.map("map", { zoomControl: true, crs: mapCRS() });
    setPlainLeafletAttribution(map);
    const defaultView = mapDefaultView();
    map.setView([defaultView.lat, defaultView.lng], 12);

    L.tileLayer(tileLayerUrl(), tileLayerOptions()).addTo(map);

    const markerLayer = cameraMarkerLayer();
    visibleCameras.forEach((camera) => {
      const marker = L.marker([camera.lat, camera.lng], { icon: cameraIcon(camera.direction, camera.viewAngle, 42) });
      marker.bindPopup(cameraPopupHtml(camera), { className: "camera-popup", maxWidth: 280 });
      markerLayer.addLayer(marker);
    });
    markerLayer.addTo(map);

    fitMapToCameras(map, visibleCameras);
    map.on("popupopen", (event) => initPreviewRefresh(event.popup.getElement()));
  }

  function initCameraPositionEditor() {
    const container = document.getElementById("camera-position-map");
    if (!window.L || !container) return;

    const panel = container.closest("[data-camera-location-options]");
    const init = () => {
      if (container.dataset.cameraPositionEditorBound === "1") return;
      if (panel && !panel.open) return;
      container.dataset.cameraPositionEditorBound = "1";
      initCameraPositionEditorMap(container);
    };
    if (panel) {
      panel.addEventListener("toggle", init);
    }
    init();
  }

  function initCameraPositionEditorMap(container) {
    const latInput = document.getElementById("camera-latitude") || document.querySelector('input[name="latitude"]');
    const lngInput = document.getElementById("camera-longitude") || document.querySelector('input[name="longitude"]');
    const directionInput = document.getElementById("camera-direction") || document.querySelector('input[name="direction_deg"]');
    if (!latInput || !lngInput) return;

    let committed = readEditorState();
    let pending = null;
    const defaultView = mapDefaultView();
    const start = hasPoint(committed) ? [committed.lat, committed.lng] : [defaultView.lat, defaultView.lng];
    const editorMap = L.map(container, { zoomControl: true, crs: mapCRS() }).setView(start, hasPoint(committed) ? 16 : 4);
    setPlainLeafletAttribution(editorMap);
    const confirmBar = createMapConfirmBar(container);
    let cameraMarker = null;
    let directionMarker = null;
    let directionLine = null;

    L.tileLayer(tileLayerUrl(), tileLayerOptions()).addTo(editorMap);

    const stageChange = (next, pan = false) => {
      pending = normalizeEditorState(next);
      renderEditorState(pending);
      showMapConfirm(confirmBar, true);
      if (pan && hasPoint(pending)) {
        editorMap.setView([pending.lat, pending.lng], Math.max(editorMap.getZoom(), 15));
      }
    };

    const syncFromInputs = () => {
      committed = readEditorState();
      pending = null;
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
      if (hasPoint(committed)) {
        editorMap.setView([committed.lat, committed.lng], Math.max(editorMap.getZoom(), 15));
      }
    };

    renderEditorState(committed);
    editorMap.on("click", (event) => {
      const base = pending || committed;
      stageChange({ ...base, lat: event.latlng.lat, lng: event.latlng.lng });
    });
    editorMap.on("zoomend", () => renderEditorState(pending || committed));
    latInput.addEventListener("change", syncFromInputs);
    lngInput.addEventListener("change", syncFromInputs);
    directionInput?.addEventListener("change", syncFromInputs);
    confirmBar.querySelector(".camera-map-apply")?.addEventListener("click", () => {
      if (!pending) return;
      committed = pending;
      pending = null;
      writeEditorState(committed);
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
    });
    confirmBar.querySelector(".camera-map-cancel")?.addEventListener("click", () => {
      pending = null;
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
    });
    document.querySelector(".camera-map-clear")?.addEventListener("click", () => {
      stageChange({ ...committed, lat: NaN, lng: NaN });
    });
    container.closest("form")?.addEventListener("submit", (event) => {
      if (!pending) return;
      event.preventDefault();
      showMapConfirm(confirmBar, true, true);
    });
    window.setTimeout(() => editorMap.invalidateSize(), 0);

    function readEditorState() {
      return normalizeEditorState({
        lat: toNumber(latInput.value),
        lng: toNumber(lngInput.value),
        direction: directionInput ? toNumber(directionInput.value) : 0
      });
    }

    function writeEditorState(state) {
      if (hasPoint(state)) {
        latInput.value = state.lat.toFixed(7);
        lngInput.value = state.lng.toFixed(7);
      } else {
        latInput.value = "";
        lngInput.value = "";
      }
      if (directionInput) {
        directionInput.value = String(state.direction);
      }
    }

    function renderEditorState(state) {
      if (!hasPoint(state)) {
        removeLayer(cameraMarker);
        removeLayer(directionMarker);
        removeLayer(directionLine);
        cameraMarker = null;
        directionMarker = null;
        directionLine = null;
        return;
      }

      const center = L.latLng(state.lat, state.lng);
      const target = directionTarget(center, state.direction, editorMap);
      if (!cameraMarker) {
        cameraMarker = L.marker(center, { draggable: true, icon: cameraIcon(state.direction, 60, 54) }).addTo(editorMap);
        cameraMarker.on("dragend", () => {
          const latlng = cameraMarker.getLatLng();
          const base = pending || committed;
          stageChange({ ...base, lat: latlng.lat, lng: latlng.lng });
        });
      } else {
        cameraMarker.setLatLng(center);
        cameraMarker.setIcon(cameraIcon(state.direction, 60, 54));
      }

      if (!directionMarker) {
        directionMarker = L.marker(target, { draggable: true, icon: directionHandleIcon(), zIndexOffset: 1000 }).addTo(editorMap);
        directionMarker.on("dragend", () => {
          const base = pending || committed;
          if (!hasPoint(base)) return;
          stageChange({ ...base, direction: bearingFromTarget(L.latLng(base.lat, base.lng), directionMarker.getLatLng(), editorMap) });
        });
      } else {
        directionMarker.setLatLng(target);
      }

      if (!directionLine) {
        directionLine = L.polyline([center, target], {
          color: "#C1964E",
          weight: 3,
          opacity: 0.9,
          dashArray: "6 6",
          className: "camera-direction-line"
        }).addTo(editorMap);
      } else {
        directionLine.setLatLngs([center, target]);
      }
    }
  }

  function initPlayer() {
    const page = document.querySelector(".player-page");
    if (!page) return;

    const stage = page.querySelector(".player-stage") || page;
    const frame = page.querySelector(".player-frame");
    const button = page.querySelector(".player-fullscreen");
    const backUrl = page.dataset.backUrl || "/";
    const fullscreenTarget = (stage.requestFullscreen || stage.webkitRequestFullscreen) ? stage : frame;
    const requestFullscreen = fullscreenTarget?.requestFullscreen || fullscreenTarget?.webkitRequestFullscreen;
    const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen;
    const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement;

    if (button && requestFullscreen) {
      button.addEventListener("click", async () => {
        try {
          if (fullscreenElement() && exitFullscreen) {
            await exitFullscreen.call(document);
          } else {
            await requestFullscreen.call(fullscreenTarget);
          }
        } catch (_error) {
          button.blur();
        }
      });

      const syncFullscreenState = () => {
        const active = fullscreenElement() === fullscreenTarget;
        const label = active ? tr("collapse", "Свернуть") : tr("fullscreen", "На весь экран");
        page.classList.toggle("is-fullscreen", active);
        button.textContent = label;
        button.setAttribute("aria-label", label);
        button.setAttribute("title", label);
      };
      document.addEventListener("fullscreenchange", syncFullscreenState);
      document.addEventListener("webkitfullscreenchange", syncFullscreenState);
    } else if (button) {
      button.hidden = true;
    }

    [page.querySelector(".player-edge-swipe"), page.querySelector(".player-toolbar")]
      .filter(Boolean)
      .forEach((zone) => bindBackSwipe(zone, backUrl));
  }

  function bindBackSwipe(element, backUrl) {
    let startX = 0;
    let startY = 0;
    let startAt = 0;

    element.addEventListener("touchstart", (event) => {
      const touch = event.changedTouches[0];
      startX = touch.clientX;
      startY = touch.clientY;
      startAt = Date.now();
    }, { passive: true });

    element.addEventListener("touchend", (event) => {
      const touch = event.changedTouches[0];
      const dx = touch.clientX - startX;
      const dy = touch.clientY - startY;
      const elapsed = Date.now() - startAt;
      if (elapsed < 700 && dx > 72 && Math.abs(dy) < 55) {
        window.location.assign(backUrl);
      }
    }, { passive: true });
  }

  function createMapConfirmBar(container) {
    const field = container.closest(".camera-position-field") || container.parentElement;
    const bar = document.createElement("div");
    bar.className = "camera-map-confirm";
    bar.hidden = true;
    bar.innerHTML = `
      <span>${escapeHtml(tr("mapChangePending", "Подтвердите изменение на карте"))}</span>
      <div>
        <button type="button" class="primary camera-map-apply">${escapeHtml(tr("apply", "Применить"))}</button>
        <button type="button" class="camera-map-cancel">${escapeHtml(tr("cancel", "Отменить"))}</button>
      </div>
    `;
    field.insertBefore(bar, container);
    return bar;
  }

  function showMapConfirm(bar, visible, attention = false) {
    bar.hidden = !visible;
    bar.classList.toggle("attention", Boolean(visible && attention));
    if (visible && attention) {
      bar.scrollIntoView({ block: "nearest" });
    }
  }

  function fitMapToCameras(map, cameras) {
    if (!cameras.length) return;

    if (cameras.length === 1) {
      map.setView([cameras[0].lat, cameras[0].lng], 16);
      return;
    }

    const bounds = L.latLngBounds(cameras.map((camera) => [camera.lat, camera.lng]));
    const padding = map.getSize().x < 520 ? [28, 28] : [52, 52];
    map.fitBounds(bounds, {
      paddingTopLeft: padding,
      paddingBottomRight: padding,
      maxZoom: 16
    });
  }

  function setPlainLeafletAttribution(map) {
    map.attributionControl?.setPrefix('<a href="https://leafletjs.com" title="A JavaScript library for interactive maps">Leaflet</a>');
  }

  function cameraMarkerLayer() {
    if (typeof L.markerClusterGroup !== "function") {
      return L.layerGroup();
    }

    return L.markerClusterGroup({
      showCoverageOnHover: false,
      removeOutsideVisibleBounds: true,
      spiderfyOnMaxZoom: true,
      disableClusteringAtZoom: 18,
      maxClusterRadius: 56,
      iconCreateFunction(cluster) {
        const count = cluster.getChildCount();
        return L.divIcon({
          className: "camera-cluster-icon",
          html: `<div class="camera-cluster" data-digits="${String(count).length}"><span>${escapeHtml(count)}</span></div>`,
          iconSize: [52, 52],
          iconAnchor: [26, 26]
        });
      }
    });
  }

  function normalizeEditorState(state) {
    return {
      lat: toNumber(state.lat),
      lng: toNumber(state.lng),
      direction: normalizeDirection(state.direction ?? state.dir ?? 0)
    };
  }

  function hasPoint(state) {
    return Number.isFinite(state.lat) && Number.isFinite(state.lng);
  }

  function directionTarget(center, direction, map) {
    const point = map.latLngToLayerPoint(center);
    const radians = normalizeDirection(direction) * Math.PI / 180;
    return map.layerPointToLatLng(L.point(
      point.x + Math.sin(radians) * 72,
      point.y - Math.cos(radians) * 72
    ));
  }

  function bearingFromTarget(center, target, map) {
    const a = map.latLngToLayerPoint(center);
    const b = map.latLngToLayerPoint(target);
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return 0;
    return normalizeDirection(Math.atan2(dx, -dy) * 180 / Math.PI);
  }

  function removeLayer(layer) {
    if (layer) {
      layer.remove();
    }
  }

  function normalizeDirection(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 0;
    return ((Math.round(number) % 360) + 360) % 360;
  }

  function initPlayerBackBridge() {
    const frames = Array.from(document.querySelectorAll(".player-frame"));
    if (!frames.length) return;

    window.addEventListener("message", (event) => {
      const data = event.data || {};
      if (!data || data.type !== "sesame-dvr:player-back") return;
      if (!frames.some((frame) => frame.contentWindow === event.source)) return;

      const targetUrl = safeSameOriginUrl(data.url);
      if (!targetUrl) return;

      event.source?.postMessage({ type: "sesame-dvr:player-back-ack", id: data.id || "" }, event.origin || "*");
      if (history.length > 1 && referrerMatches(targetUrl)) {
        history.back();
        return;
      }

      window.location.assign(relativeUrl(targetUrl));
    });
  }

  function safeSameOriginUrl(value) {
    try {
      const url = new URL(String(value || ""), window.location.href);
      return url.origin === window.location.origin ? url : null;
    } catch (_error) {
      return null;
    }
  }

  function referrerMatches(targetUrl) {
    if (!document.referrer) return false;
    const referrerUrl = safeSameOriginUrl(document.referrer);
    return Boolean(referrerUrl && relativeUrl(referrerUrl) === relativeUrl(targetUrl));
  }

  function relativeUrl(url) {
    return `${url.pathname}${url.search}${url.hash}`;
  }

  function cameraIcon(direction, viewAngle = 60, markerHitSize = 42) {
    const angle = normalizeDirection(direction);
    const fov = clamp(Number(viewAngle) || 60, 12, 170);
    const coneLength = 72;
    const coneWidth = Math.round(clamp(2 * Math.tan(fov * Math.PI / 360) * coneLength, 34, 132));
    const hitSize = Math.round(clamp(Number(markerHitSize) || 42, 30, 72));
    return L.divIcon({
      className: "camera-marker-icon",
      html: `<div class="camera-marker" style="--camera-direction:${angle}deg;--camera-cone-width:${coneWidth}px;--camera-cone-length:${coneLength}px"><span class="camera-view-cone"></span><span class="camera-marker-dot">●</span></div>`,
      iconSize: [hitSize, hitSize],
      iconAnchor: [hitSize / 2, hitSize / 2],
      popupAnchor: [0, -Math.round(hitSize / 2)]
    });
  }

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function directionHandleIcon() {
    return L.divIcon({
      className: "",
      html: '<div class="camera-direction-handle"></div>',
      iconSize: [22, 22],
      iconAnchor: [11, 11]
    });
  }

  function cameraPopupHtml(camera) {
    const stateText = camera.streamUnavailable
      ? tr("streamUnavailable", "Поток недоступен")
      : tr("previewUnavailable", "Превью недоступно");
    const unavailableClass = camera.streamUnavailable ? " stream-unavailable" : "";
    const previewLabel = tr("openPlayer", tr("openVideo", "Открыть видео"));
    const preview = camera.preview
      ? `<a class="map-popup-preview is-loading${unavailableClass}" href="${escapeHtml(camera.player)}" aria-label="${escapeHtml(previewLabel)}"><img data-preview-src="${escapeHtml(camera.preview)}" data-preview-refresh="off" alt="" loading="lazy" decoding="async" hidden><span class="preview-spinner" aria-hidden="true"></span><span class="preview-state map-popup-preview-state">${escapeHtml(stateText)}</span><span class="preview-play" aria-hidden="true"></span></a>`
      : `<div class="map-popup-preview no-preview${unavailableClass}"><span class="preview-spinner" aria-hidden="true"></span><span class="preview-state map-popup-preview-state">${escapeHtml(stateText)}</span></div>`;
    const favoriteTitle = camera.favorite
      ? tr("removeFavorite", "Удалить из избранного")
      : tr("addFavorite", "Добавить в избранное");
    const favoriteClass = camera.favorite ? "favorite active" : "favorite";
    const favoriteIcon = camera.favorite ? "★" : "☆";

    return `
      <div class="map-popup-card">
        ${preview}
        <strong>${escapeHtml(camera.name)}</strong>
        <span>${escapeHtml(camera.server || "")}</span>
        <div class="map-popup-actions">
          <a href="${escapeHtml(camera.player)}">${escapeHtml(tr("openVideo", "Открыть видео"))}</a>
          <form method="post" action="/favorite/toggle" class="favorite-form">
            <input type="hidden" name="csrf" value="${escapeHtml(window.SESAME_CSRF || "")}">
            <input type="hidden" name="camera_id" value="${escapeHtml(camera.id)}">
            <button title="${escapeHtml(favoriteTitle)}" aria-label="${escapeHtml(favoriteTitle)}" class="${favoriteClass}">${favoriteIcon}</button>
          </form>
        </div>
      </div>
    `;
  }

  function initPreviewRefresh(root = document) {
    const images = Array.from(root.querySelectorAll("img[data-preview-src]"));
    if (!images.length) return;

    images.forEach((image, index) => {
      if (image.dataset.previewRefreshBound === "1") return;
      image.dataset.previewRefreshBound = "1";
      image.addEventListener("load", () => markPreviewReady(image));
      image.addEventListener("error", () => markPreviewMissing(image));
      if (!image.getAttribute("src")) {
        const startDelay = Math.min(index * 500, 4000);
        window.setTimeout(() => {
          loadPreviewImage(image, image.dataset.previewSrc, { markMissingOnError: true });
        }, startDelay);
      } else if (image.complete) {
        if (image.naturalWidth > 0) {
          markPreviewReady(image);
        } else {
          markPreviewMissing(image);
        }
      }
      if (image.dataset.previewRefresh === "off") return;
      const intervalMs = Math.max(10000, Number(image.dataset.previewRefreshMs) || 30000);
      window.setTimeout(function refreshLoop() {
        refreshPreview(image);
        window.setTimeout(refreshLoop, intervalMs);
      }, intervalMs + Math.min(index * 1200, intervalMs));
    });
  }

  function initAssignmentPickers() {
    document.querySelectorAll("[data-assignment-picker]").forEach((picker) => {
      const search = picker.querySelector(".assignment-search");
      const selectedOnly = picker.querySelector(".assignment-selected-only");
      const count = picker.querySelector(".assignment-count");
      const empty = picker.querySelector(".assignment-empty");
      const rows = Array.from(picker.querySelectorAll(".assignment-row"));
      const update = () => {
        const query = String(search?.value || "").trim().toLowerCase();
        const selectedOnlyMode = selectedOnly?.getAttribute("aria-pressed") === "true";
        let selected = 0;
        let visible = 0;
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          const checked = Boolean(checkbox?.checked);
          const matches = row.textContent.toLowerCase().includes(query);
          const show = matches && (!selectedOnlyMode || checked);
          if (checked) selected += 1;
          if (show) visible += 1;
          row.hidden = !show;
        });
        if (count) {
          count.textContent = `${tr("selectedCount", "Выбрано")}: ${selected} / ${rows.length}`;
        }
        if (empty) {
          empty.hidden = visible > 0;
        }
      };

      search?.addEventListener("input", update);
      selectedOnly?.addEventListener("click", () => {
        const active = selectedOnly.getAttribute("aria-pressed") === "true";
        selectedOnly.setAttribute("aria-pressed", active ? "false" : "true");
        selectedOnly.classList.toggle("active", !active);
        update();
      });
      rows.forEach((row) => row.querySelector('input[type="checkbox"]')?.addEventListener("change", update));
      update();
    });
  }

  function initDvrStreamImport(root = document) {
    root.querySelectorAll("[data-dvr-import-form]").forEach((form) => {
      if (form.dataset.dvrImportBound === "1") return;
      form.dataset.dvrImportBound = "1";
      const rows = Array.from(form.querySelectorAll("[data-dvr-import-row]"));
      const search = form.querySelector("[data-dvr-import-search]");
      const count = form.querySelector("[data-dvr-import-count]");
      const submit = form.querySelector("[data-dvr-import-submit]");
      const empty = form.querySelector("[data-dvr-import-filter-empty]");

      const update = () => {
        const query = String(search?.value || "").trim().toLocaleLowerCase();
        let selected = 0;
        let visible = 0;
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          const haystack = String(row.dataset.search || row.textContent || "").toLocaleLowerCase();
          const show = query === "" || haystack.includes(query);
          row.hidden = !show;
          if (show) visible += 1;
          if (checkbox?.checked) selected += 1;
        });
        if (count) {
          count.textContent = `${tr("selectedCount", "Выбрано")}: ${selected} / ${rows.length}`;
        }
        if (submit) submit.disabled = selected === 0;
        if (empty) empty.hidden = visible > 0;
      };

      search?.addEventListener("input", update);
      rows.forEach((row) => row.querySelector('input[type="checkbox"]')?.addEventListener("change", update));
      form.querySelector("[data-dvr-import-select-all]")?.addEventListener("click", () => {
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          if (checkbox) checkbox.checked = true;
        });
        update();
      });
      form.querySelector("[data-dvr-import-clear-all]")?.addEventListener("click", () => {
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          if (checkbox) checkbox.checked = false;
        });
        update();
      });
      update();
    });
  }

  function initDensitySwitch() {
    const switcher = document.querySelector(".density-switch");
    const grid = document.querySelector(".camera-grid");
    if (!switcher || !grid) return;

    const setColumns = (cols) => {
      const normalized = String(clamp(Math.round(Number(cols) || 3), 2, 6));
      grid.classList.remove("cols-2", "cols-3", "cols-4", "cols-5", "cols-6");
      grid.classList.add(`cols-${normalized}`);

      switcher.querySelectorAll("[data-cols]").forEach((link) => {
        const active = link.dataset.cols === normalized;
        link.classList.toggle("active", active);
        link.setAttribute("aria-current", active ? "true" : "false");
      });

      document.querySelectorAll('input[name="cols"]').forEach((input) => {
        input.value = normalized;
      });
      updateViewerLinks(normalized);
    };

    const current = switcher.querySelector("[data-cols].active")?.dataset.cols;
    if (current) {
      setColumns(current);
    }
  }

  function updateViewerLinks(cols) {
    document.querySelectorAll(".viewer-filters a[href^='/'], .pager a[href^='/']").forEach((link) => {
      if (link.closest(".density-switch")) return;
      const href = link.getAttribute("href");
      if (!href) return;
      const url = new URL(href, window.location.origin);
      if (url.pathname !== "/") return;
      url.searchParams.set("cols", cols);
      link.setAttribute("href", url.pathname + url.search + url.hash);
    });
  }

  function initGroupTreePickers(root = document) {
    const pickers = Array.from(root.querySelectorAll("[data-group-tree-picker]"));
    const toggles = Array.from(root.querySelectorAll("[data-group-tree-toggle]"));
    const jsonInputs = Array.from(root.querySelectorAll("[data-group-tree-json]"));
    if (!pickers.length && !toggles.length && !jsonInputs.length) return;

    const closePicker = (picker) => {
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;
      menu.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
    };

    const openPicker = (picker) => {
      pickers.forEach((other) => {
        if (other !== picker) closePicker(other);
      });
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;
      menu.hidden = false;
      trigger.setAttribute("aria-expanded", "true");
    };

    const toggleNode = (toggle) => {
      const node = toggle.closest("[data-group-tree-node]");
      const children = Array.from(node?.children || []).find((child) => child.matches("[data-group-tree-children]"));
      if (!children) return;
      const expanded = toggle.getAttribute("aria-expanded") === "true";
      const nextExpanded = !expanded;
      children.hidden = !nextExpanded;
      toggle.setAttribute("aria-expanded", nextExpanded ? "true" : "false");
      toggle.textContent = nextExpanded ? "-" : "+";
      toggle.setAttribute("aria-label", nextExpanded ? (toggle.dataset.collapseLabel || "") : (toggle.dataset.expandLabel || ""));
      node?.classList.toggle("is-expanded", nextExpanded);
    };

    const syncGroupTreeJson = (field) => {
      const input = field?.querySelector("[data-group-tree-json]");
      if (!field || !input) return;
      const ids = [];
      const seen = new Set();
      field.querySelectorAll('.group-tree-checkbox-list input[type="checkbox"]:checked').forEach((checkbox) => {
        const id = Number.parseInt(checkbox.value, 10);
        if (Number.isFinite(id) && id > 0 && !seen.has(id)) {
          seen.add(id);
          ids.push(id);
        }
      });
      input.value = JSON.stringify(ids);
    };

    jsonInputs.forEach((input) => {
      const field = input.closest(".group-tree-field");
      if (!field || field.dataset.groupTreeJsonBound === "1") return;
      field.dataset.groupTreeJsonBound = "1";
      field.addEventListener("change", (event) => {
        if (event.target?.matches?.('.group-tree-checkbox-list input[type="checkbox"]')) {
          syncGroupTreeJson(field);
        }
      });
      field.closest("form")?.addEventListener("submit", () => syncGroupTreeJson(field), { capture: true });
      syncGroupTreeJson(field);
    });

    toggles.forEach((toggle) => {
      toggle.addEventListener("click", (event) => {
        event.preventDefault();
        toggleNode(toggle);
      });
    });

    root.querySelectorAll("[data-group-tree-check-all], [data-group-tree-clear-all]").forEach((button) => {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        const field = button.closest(".group-tree-field");
        const checked = button.hasAttribute("data-group-tree-check-all");
        field?.querySelectorAll('.group-tree-checkbox-list input[type="checkbox"]').forEach((checkbox) => {
          checkbox.checked = checked;
          checkbox.dispatchEvent(new Event("change", { bubbles: true }));
        });
        syncGroupTreeJson(field);
      });
    });

    root.querySelectorAll("[data-group-tree-search]").forEach((searchInput) => {
      if (searchInput.dataset.bound === "1") return;
      searchInput.dataset.bound = "1";
      const field = searchInput.closest(".group-tree-field");
      const list = field?.querySelector(".group-tree-list");
      if (!list) return;
      searchInput.addEventListener("input", () => {
        const q = searchInput.value.trim().toLowerCase();
        list.querySelectorAll(":scope > [data-group-tree-node]").forEach((node) => {
          const header = node.querySelector(":scope > .group-tree-row > .group-tree-folder-header");
          const match = !q || (header?.textContent?.toLowerCase().includes(q) ?? false);
          node.style.display = match ? "" : "none";
        });
      });
    });

    pickers.forEach((picker) => {
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;

      trigger.addEventListener("click", (event) => {
        event.preventDefault();
        if (menu.hidden) {
          openPicker(picker);
        } else {
          closePicker(picker);
        }
      });
    });

    document.addEventListener("click", (event) => {
      pickers.forEach((picker) => {
        if (!picker.contains(event.target)) {
          closePicker(picker);
        }
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      pickers.forEach(closePicker);
    });
  }

  function initConfirmDialogs(root = document) {
    root.querySelectorAll("form[data-confirm]").forEach((form) => {
      if (form.dataset.confirmBound === "1") return;
      form.dataset.confirmBound = "1";
      form.addEventListener("submit", (event) => {
        if (form.dataset.confirmAccepted === "1") {
          delete form.dataset.confirmAccepted;
          return;
        }
        event.preventDefault();
        showConfirmDialog(form);
      });
    });
  }

  function showConfirmDialog(form) {
    const message = form.dataset.confirm || "";
    const submitButton = form.querySelector("button[type=submit]");
    const okLabel = form.dataset.confirmOk || (submitButton ? submitButton.textContent.trim() : tr("js.confirm", "Подтвердить"));
    const cancelLabel = tr("js.confirmCancel", "Отмена");
    const dialog = document.createElement("dialog");
    dialog.className = "confirm-dialog";
    dialog.innerHTML =
      '<form method="dialog" class="confirm-dialog-form">' +
      "<p class=\"confirm-dialog-message\"></p>" +
      '<div class="confirm-dialog-actions">' +
      '<button class="btn confirm-cancel" value="cancel">' + escapeHtml(cancelLabel) + "</button>" +
      '<button class="btn confirm-ok" value="ok">' + escapeHtml(okLabel) + "</button>" +
      "</div></form>";
    dialog.querySelector(".confirm-dialog-message").textContent = message;
    const okButton = dialog.querySelector(".confirm-ok");
    const formClass = form.className || "";
    if (formClass.includes("danger") || submitButton?.classList.contains("danger")) {
      okButton.classList.add("danger");
    }
    const cancelButton = dialog.querySelector(".confirm-cancel");
    const closeDialog = () => {
      dialog.close("cancel");
    };
    dialog.addEventListener("close", () => {
      if (dialog.returnValue === "ok") {
        form.dataset.confirmAccepted = "1";
        form.requestSubmit();
      }
      dialog.remove();
    });
    dialog.addEventListener("cancel", () => {
      closeDialog();
    });
    dialog.addEventListener("click", (event) => {
      if (event.target === dialog) closeDialog();
    });
    document.body.appendChild(dialog);
    dialog.showModal();
    cancelButton.focus();
  }

  function initSubmitProgress(root = document) {
    root.querySelectorAll("form[data-submit-progress]").forEach((form) => {
      if (form.dataset.submitProgressBound === "1") return;
      form.dataset.submitProgressBound = "1";
      form.addEventListener("submit", () => {
        const label = form.dataset.submitProgress || tr("js.saving", "Saving...");
        form.classList.add("is-submitting");
        form.setAttribute("aria-busy", "true");
        form.querySelectorAll("[data-submit-status]").forEach((status) => {
          status.textContent = label;
          status.hidden = false;
        });
        form.querySelectorAll("[data-submit-button]").forEach((button) => {
          if ("disabled" in button) button.disabled = true;
          button.setAttribute("aria-disabled", "true");
        });
      });
    });
  }

  function initCameraFormVisibility(root = document) {
    root.querySelectorAll("form").forEach((form) => {
      if (form.dataset.cameraFormVisibilityBound === "1") return;
      const modeSelect = form.querySelector("[data-camera-mode-select]");
      const watermarkToggle = form.querySelector("[data-watermark-toggle]");
      if (!modeSelect && !watermarkToggle) return;
      form.dataset.cameraFormVisibilityBound = "1";

      const sync = () => {
        const agentVisible = modeSelect?.value === "edge_agent";
        form.querySelectorAll("[data-camera-agent-field]").forEach((field) => {
          field.hidden = !agentVisible;
        });

        const onvifVisible = modeSelect?.value === "managed";
        form.querySelectorAll("[data-camera-onvif-field]").forEach((field) => {
          field.hidden = !onvifVisible;
        });

        const watermarkVisible = !!watermarkToggle?.checked;
        form.querySelectorAll("[data-watermark-dependent]").forEach((field) => {
          field.hidden = !watermarkVisible;
        });
      };

      modeSelect?.addEventListener("change", sync);
      watermarkToggle?.addEventListener("change", sync);
      sync();
    });
  }

  function initDvrStreamOptions(root = document) {
    root.querySelectorAll("[data-dvr-stream-options]").forEach((container) => {
      if (container.dataset.dvrStreamOptionsBound === "1") return;
      container.dataset.dvrStreamOptionsBound = "1";

      const sync = () => {
        container.querySelectorAll("[data-dvr-dependent]").forEach((section) => {
          const key = section.dataset.dvrDependent || "";
          const toggle = Array.from(container.querySelectorAll("[data-dvr-toggle]")).find((candidate) => {
            return (candidate.dataset.dvrToggle || "") === key;
          });
          section.hidden = !toggle?.checked;
        });
      };

      container.querySelectorAll("[data-dvr-toggle]").forEach((toggle) => {
        toggle.addEventListener("change", sync);
      });
      sync();
    });
  }

  function initLocalTimes(root = document) {
    const times = Array.from(root.querySelectorAll("time.local-time[datetime]"));
    if (!times.length) return;

    const locale = document.documentElement.lang || undefined;
    const formatter = new Intl.DateTimeFormat(locale, {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    });
    const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || "";

    times.forEach((time) => {
      if (time.dataset.localTimeBound === "1") return;
      const date = new Date(time.dateTime || time.getAttribute("datetime") || "");
      if (Number.isNaN(date.getTime())) return;
      time.dataset.localTimeBound = "1";
      time.textContent = formatter.format(date);
      if (timeZone) {
        time.title = `${time.getAttribute("datetime")} -> ${timeZone}`;
      }
    });
  }

  function initStaticTokens(root = document) {
    const MASK = "*******";
    const maskToken = (input, button) => {
      input.value = MASK;
      input.title = "";
      delete input.dataset.staticTokenLoading;
      if (button) {
        const reveal = tr("staticTokenReveal", "Show and copy");
        button.title = reveal;
        button.setAttribute("aria-label", reveal);
        button.classList.remove("is-copied");
      }
    };
    const copyToken = (input) => {
      if (navigator.clipboard?.writeText) {
        return navigator.clipboard.writeText(input.value).catch(() => fallbackCopy(input));
      }
      return fallbackCopy(input);
    };
    root.querySelectorAll("[data-static-token-reveal]").forEach((el) => {
      if (el.dataset.staticTokenRevealBound === "1") return;
      el.dataset.staticTokenRevealBound = "1";
      el.addEventListener("click", async () => {
        const container = el.closest(".static-token-field") || el;
        const input = container.querySelector(".static-token-input");
        const button = container.querySelector(".static-token-copy");
        if (!input) return;
        if (input.value !== MASK) {
          input.select();
          await copyToken(input).catch(() => {});
          if (el === button) maskToken(input, button);
          return;
        }
        if (input.dataset.staticTokenLoading === "1") return;
        input.dataset.staticTokenLoading = "1";
        try {
          const userId = encodeURIComponent(el.dataset.staticTokenUser || "");
          const response = await fetch(`/api/portal/v1/users/${userId}/static-token`, {
            headers: { Accept: "application/json" }
          });
          if (!response.ok) throw new Error(String(response.status));
          const data = await response.json();
          const token = typeof data.token === "string" && data.token !== "" ? data.token : "";
          if (!token) throw new Error("empty");
          input.value = token;
          await copyToken(input);
          const copied = tr("staticTokenCopied", "Copied");
          if (button) {
            button.title = copied;
            button.setAttribute("aria-label", copied);
            button.classList.add("is-copied");
          }
          input.title = copied;
          input.select();
        } catch {
          maskToken(input, button);
        } finally {
          delete input.dataset.staticTokenLoading;
        }
      });
    });
  }

  function fallbackCopy(input) {
    try {
      input.focus();
      input.select();
      return document.execCommand("copy");
    } catch {
      return false;
    }
  }

  const PREVIEW_ERROR_RETRY_MS = 8000;

  function refreshPreview(image) {
    const source = image.dataset.previewSrc;
    if (!source) return;
    if (image.dataset.previewLoading === "1") return;

    const separator = source.includes("?") ? "&" : "?";
    const nextSrc = `${source}${separator}_=${Date.now()}`;
    loadPreviewImage(image, nextSrc, { markMissingOnError: false });
  }

  function loadPreviewImage(image, nextSrc, options = {}) {
    if (!nextSrc) return;
    const preloader = new Image();
    preloader.decoding = "async";
    image.dataset.previewLoading = "1";
    const container = image.closest(".preview, .map-popup-preview");
    const showLoader = options.showLoader ?? (image.hidden || !image.getAttribute("src"));
    if (showLoader) {
      container?.classList.add("is-loading");
    }

    const finish = () => {
      delete image.dataset.previewLoading;
      container?.classList.remove("is-loading");
    };
    preloader.onload = async () => {
      try {
        await preloader.decode?.();
      } catch {
        // The image is already loaded; decode is only used to avoid visible swaps.
      }
      if (!image.isConnected) {
        finish();
        return;
      }
      image.src = nextSrc;
      delete image.dataset.previewRetried;
      markPreviewReady(image);
      finish();
    };
    preloader.onerror = () => {
      if (options.markMissingOnError && image.isConnected) {
        markPreviewMissing(image);
      }
      if (image.dataset.previewRetried !== "1" && image.isConnected) {
        image.dataset.previewRetried = "1";
        window.setTimeout(() => {
          if (!image.isConnected) return;
          if (image.getAttribute("src") || image.naturalWidth > 0) return;
          const source = image.dataset.previewSrc;
          if (!source) return;
          const separator = source.includes("?") ? "&" : "?";
          loadPreviewImage(image, `${source}${separator}_=${Date.now()}-retry`, { markMissingOnError: true });
        }, PREVIEW_ERROR_RETRY_MS);
      }
      finish();
    };
    preloader.src = nextSrc;
  }

  function markPreviewReady(image) {
    image.hidden = false;
    const container = image.closest(".preview, .map-popup-preview");
    container?.classList.remove("no-preview", "is-loading");
    container?.classList.add("has-preview");
  }

  function markPreviewMissing(image) {
    image.hidden = true;
    const container = image.closest(".preview, .map-popup-preview");
    container?.classList.remove("is-loading");
    container?.classList.remove("has-preview");
    container?.classList.add("no-preview");
  }

  function toNumber(value) {
    if (value === null || value === undefined || value === "") return NaN;
    return Number(String(value).replace(",", "."));
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function tr(key, fallback) {
    return messages[key] || fallback;
  }

})();

/* Clickable table rows — clicking anywhere on a row with data-href opens its edit page */
document.addEventListener('click', function (e) {
  var row = e.target.closest('tr[data-href]');
  if (!row) return;
  if (e.target.closest('a, button, form, input, select')) return;
  window.location.href = row.getAttribute('data-href');
});

/* Tab switcher for group edit page */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.tab-btn');
  if (!btn) return;
  var tabs = btn.closest('.group-edit-tabs');
  if (!tabs) return;
  var tabId = btn.getAttribute('data-tab');
  tabs.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.toggle('active', b === btn); });
  tabs.querySelectorAll('.tab-panel').forEach(function (p) {
    var isTarget = p.getAttribute('data-tab-panel') === tabId;
    if (isTarget) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', ''); }
  });
  var url = new URL(window.location);
  url.searchParams.set('tab', tabId);
  history.replaceState(null, '', url);
});

/* Folder expand/collapse in group edit — show cameras list */
document.addEventListener('click', function (e) {
  var row = e.target.closest('.folder-expand-row');
  if (!row) return;
  var folderId = row.getAttribute('data-folder-id');
  if (!folderId) return;
  var camRow = row.closest('tbody').querySelector('.folder-cameras-row[data-folder-id="' + folderId + '"]');
  if (!camRow) return;
  var isOpen = row.classList.toggle('open');
  if (isOpen) { camRow.removeAttribute('hidden'); } else { camRow.setAttribute('hidden', ''); }
});

/* Folder action dropdown — "Добавить камеру" */
document.addEventListener('click', function (e) {
  var trigger = e.target.closest('.folder-action-trigger');
  if (trigger) {
    e.stopPropagation();
    var dropdown = trigger.closest('.folder-action-dropdown');
    if (dropdown) {
      var wasOpen = dropdown.classList.contains('open');
      document.querySelectorAll('.folder-action-dropdown.open').forEach(function (d) {
        d.classList.remove('open');
        var m = d.querySelector('.folder-action-menu');
        if (m) { m.style.left = ''; m.style.top = ''; }
      });
      if (!wasOpen) {
        dropdown.classList.add('open');
        var menu = dropdown.querySelector('.folder-action-menu');
        if (menu) {
          var r = trigger.getBoundingClientRect();
          menu.style.left = r.left + 'px';
          if (r.bottom + 100 > window.innerHeight) {
            menu.style.top = (r.top - 2) + 'px';
            menu.style.bottom = 'auto';
            menu.style.transform = 'translateY(-100%)';
          } else {
            menu.style.top = (r.bottom + 2) + 'px';
            menu.style.bottom = 'auto';
            menu.style.transform = '';
          }
        }
      }
    }
    return;
  }
  if (!e.target.closest('.folder-action-menu')) {
    document.querySelectorAll('.folder-action-dropdown.open').forEach(function (d) { d.classList.remove('open'); });
  }
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.folder-action-dropdown.open').forEach(function (d) { d.classList.remove('open'); });
  }
});

/* Camera picker modal — open dialog + search filter */
document.addEventListener('click', function (e) {
  var pickBtn = e.target.closest('.folder-pick-camera-btn');
  if (pickBtn) {
    var folderId = pickBtn.getAttribute('data-folder-id');
    var dialog = document.getElementById('camera-picker-' + folderId);
    if (dialog && typeof dialog.showModal === 'function') {
      dialog.showModal();
      var search = dialog.querySelector('.camera-picker-search');
      if (search) { search.value = ''; search.focus(); filterCameraPicker(dialog); }
    }
    return;
  }
  var closeBtn = e.target.closest('.camera-picker-close');
  if (closeBtn) {
    var dlg = closeBtn.closest('.camera-picker-dialog');
    if (dlg) dlg.close();
    return;
  }
  if (e.target.classList && e.target.classList.contains('camera-picker-dialog')) {
    e.target.close();
  }
});
document.addEventListener('input', function (e) {
  if (e.target.classList && e.target.classList.contains('camera-picker-search')) {
    filterCameraPicker(e.target.closest('.camera-picker-dialog'));
  }
});
function filterCameraPicker(dialog) {
  if (!dialog) return;
  var query = (dialog.querySelector('.camera-picker-search').value || '').toLowerCase();
  dialog.querySelectorAll('.camera-pick-item').forEach(function (item) {
    var name = item.getAttribute('data-camera-name') || '';
    item.style.display = name.indexOf(query) !== -1 ? '' : 'none';
  });
}

function initInstallPrompt() {
  if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) return;
  if (!document.querySelector('.shell')) return;

  var dismissed = localStorage.getItem('installDismissed');
  if (dismissed && (Date.now() - parseInt(dismissed, 10)) < 30 * 24 * 60 * 60 * 1000) return;

  var deferredPrompt = null;
  var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.MSStream;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    showInstallBanner(deferredPrompt, false);
  });

  if (isIOS) {
    showInstallBanner(null, true);
  }

  function showInstallBanner(deferredPrompt, isIOS) {
    if (document.querySelector('.install-prompt-banner')) return;

    var banner = document.createElement('div');
    banner.className = 'install-prompt-banner';

    var text = document.createElement('span');
    text.innerHTML = isIOS
      ? 'Установите приложение: нажмите <strong>Share</strong> &rarr; <strong>На экран Домой</strong>'
      : 'Установите приложение на рабочий стол';
    banner.appendChild(text);

    if (!isIOS && deferredPrompt) {
      var btn = document.createElement('button');
      btn.className = 'install-prompt-btn';
      btn.textContent = 'Установить';
      btn.addEventListener('click', function () {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function () {
          deferredPrompt = null;
          banner.remove();
        });
      });
      banner.appendChild(btn);
    }

    var closeBtn = document.createElement('button');
    closeBtn.className = 'install-prompt-close';
    closeBtn.innerHTML = '&times;';
    closeBtn.setAttribute('aria-label', 'Закрыть');
    closeBtn.addEventListener('click', function () {
      banner.remove();
      localStorage.setItem('installDismissed', String(Date.now()));
    });
    banner.appendChild(closeBtn);

    document.body.appendChild(banner);
  }
}

function initNavToggle() {
  var toggle = document.querySelector('[data-nav-toggle]');
  var sidebar = document.querySelector('.sidebar');
  var backdrop = document.querySelector('[data-nav-backdrop]');
  if (!toggle || !sidebar || !backdrop) return;

  function open() {
    sidebar.classList.add('nav-open');
    backdrop.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
  }
  function close() {
    sidebar.classList.remove('nav-open');
    backdrop.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  }

  toggle.addEventListener('click', function () {
    if (sidebar.classList.contains('nav-open')) { close(); } else { open(); }
  });
  backdrop.addEventListener('click', close);
  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });
}

function initCallbackLogin() {
  var tabs = document.querySelectorAll('[data-login-tab]');
  var panes = document.querySelectorAll('[data-login-pane]');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
      panes.forEach(function (pane) {
        pane.classList.toggle('active', pane.getAttribute('data-login-pane') === tab.getAttribute('data-login-tab'));
      });
    });
  });

  var generate = document.querySelector('[data-callback-generate]');
  if (generate) {
    generate.addEventListener('click', function () {
      var input = document.querySelector('[data-callback-token]');
      if (!input) return;
      input.value = callbackRandomToken();
    });
  }

  var externalGenerate = document.querySelector('[data-external-generate]');
  if (externalGenerate) {
    externalGenerate.addEventListener('click', function () {
      var input = document.querySelector('[data-external-token]');
      if (!input) return;
      input.value = callbackRandomToken();
    });
  }

  var form = document.querySelector('[data-callback-form]');
  if (!form) return;
  var pane = form.closest('[data-login-pane]');
  var status = pane.querySelector('[data-callback-status]');
  var notice = pane.querySelector('[data-callback-call-notice]');
  var timerEl = pane.querySelector('[data-callback-timer]');
  var errorEl = pane.querySelector('[data-callback-error]');
  var phoneInput = pane.querySelector('[data-callback-phone]');
  var submit = form.querySelector('button[type="submit"]');
  var lifetime = parseInt(pane.getAttribute('data-callback-lifetime') || '120', 10);
  if (isNaN(lifetime) || lifetime <= 0) lifetime = 120;
  var pollTimer = null;
  var countdownTimer = null;
  var stopped = false;

  function showError(text) {
    if (stopped) return;
    status.hidden = true;
    errorEl.textContent = text;
    errorEl.hidden = false;
  }

  function stopTimers() {
    if (pollTimer) clearInterval(pollTimer);
    if (countdownTimer) clearInterval(countdownTimer);
    pollTimer = null;
    countdownTimer = null;
  }

  function pollPending(pendingId) {
    if (stopped || pollTimer) return;
    pollTimer = setInterval(function () {
      fetch('/api/portal/v1/auth/callback/poll?pending_id=' + encodeURIComponent(pendingId))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (stopped) return;
          if (data.ok && data.status === 'confirmed') {
            completeLogin(pendingId);
          } else if (!data.ok || data.status === 'expired') {
            stopped = true;
            stopTimers();
            showError(pane.getAttribute('data-msg-expired') || '');
          }
        })
        .catch(function () {
          if (stopped) return;
        });
    }, 2000);
  }

  function completeLogin(pendingId) {
    if (stopped) return;
    stopped = true;
    stopTimers();
    status.hidden = true;
    submit.disabled = true;
    fetch('/api/portal/v1/auth/callback/complete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ pending_id: pendingId })
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.ok && data.redirect) {
          window.location.href = data.redirect;
        } else {
          errorEl.textContent = pane.getAttribute('data-msg-failed') || '';
          errorEl.hidden = false;
          submit.disabled = false;
        }
      })
      .catch(function () {
        errorEl.textContent = pane.getAttribute('data-msg-failed') || '';
        errorEl.hidden = false;
        submit.disabled = false;
      });
  }

  function startCountdown(pendingId) {
    var remaining = lifetime;
    timerEl.textContent = String(remaining);
    countdownTimer = setInterval(function () {
      remaining -= 1;
      if (remaining <= 0) {
        timerEl.textContent = '0';
        stopped = true;
        stopTimers();
        showError(pane.getAttribute('data-msg-expired') || '');
        submit.disabled = false;
        return;
      }
      timerEl.textContent = String(remaining);
    }, 1000);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (stopped) { errorEl.hidden = true; return; }
    var phone = (phoneInput.value || '').trim();
    if (!phone) { phoneInput.focus(); return; }
    errorEl.hidden = true;
    status.hidden = false;
    notice.textContent = pane.getAttribute('data-msg-waiting') || '';
    timerEl.textContent = '';
    submit.disabled = true;
    fetch('/api/portal/v1/auth/callback/start', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ phone: phone })
    })
      .then(function (res) {
        return res.json().then(function (data) { return { status: res.status, data: data }; });
      })
      .then(function (result) {
        if (stopped) return;
        submit.disabled = false;
        var data = result.data;
        if (result.status === 200 && data.ok && data.pending_id) {
          stopped = false;
          var callMsg = pane.getAttribute('data-msg-call') || '';
          notice.textContent = callMsg.indexOf('%s') !== -1 ? callMsg.replace('%s', data.callback_phone || '') : callMsg;
          timerEl.textContent = String(data.lifetime_seconds || lifetime);
          startCountdown(data.pending_id);
          pollPending(data.pending_id);
        } else if (data.error && (data.error.code === 'user_not_found' || data.error.code === 'callback_disabled' || data.error.code === 'callback_not_configured')) {
          showError(pane.getAttribute('data-msg-rejected') || '');
        } else if (data.error && (data.error.code === 'rate_limited' || data.error.code === 'pending_exists')) {
          showError(pane.getAttribute('data-msg-rate') || '');
        } else {
          showError(pane.getAttribute('data-msg-failed') || '');
        }
      })
      .catch(function () {
        if (stopped) return;
        submit.disabled = false;
        showError(pane.getAttribute('data-msg-failed') || '');
      });
  });
}

function callbackRandomToken() {
  var bytes = new Uint8Array(32);
  if (window.crypto && window.crypto.getRandomValues) {
    window.crypto.getRandomValues(bytes);
  } else {
    for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
  }
  var binary = '';
  for (var j = 0; j < bytes.length; j++) binary += String.fromCharCode(bytes[j]);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
