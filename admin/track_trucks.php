
<?php
// This file is included inside dashboard.php.
// Do not add another HTML, HEAD, BODY, or NAVBAR.
?>

<style>

.tracking-page-title {
    color: #183d2b;
    font-weight: 700;
}

.tracking-card {
    background: #fff;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.05);
    margin-bottom: 20px;
}

#collectorMap {
    width: 100%;
    height: 550px;
    border-radius: 10px;
    background: #e9efeb;
    z-index: 1;
}

.collector-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 14px 5px;
    border-bottom: 1px solid #edf0ed;
}

.collector-row:last-child {
    border-bottom: 0;
}

.collector-name {
    color: #183d2b;
    font-weight: 600;
}

.collector-coordinates {
    font-size: 12px;
    color: #77847c;
    margin-top: 4px;
}

.tracking-status {
    white-space: nowrap;
}

.tracking-indicator {
    width: 9px;
    height: 9px;
    display: inline-block;
    border-radius: 50%;
    margin-right: 5px;
    background: #22a447;
}

@media (max-width: 600px) {

    #collectorMap {
        height: 400px;
    }

    .tracking-card {
        padding: 14px;
    }

}

</style>


<!-- PAGE TITLE -->

<div class="mb-4">

    <h2 class="tracking-page-title">

        <i class="fas fa-truck"></i>

        Live Collector Tracking

    </h2>

    <p class="text-secondary">
        Monitor the latest GPS locations reported by your waste collectors.
    </p>

</div>


<!-- MAP -->

<div class="tracking-card">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">

        <h5 class="fw-bold mb-0">

            <i class="fas fa-map-marked-alt"></i>

            Collector Locations

        </h5>

        <span id="trackingLastUpdated" class="text-secondary small">
            Waiting for location data...
        </span>

    </div>

    <div id="collectorMap"></div>

    <div id="trackingError"
         class="alert alert-warning mt-3 d-none">
    </div>

</div>


<!-- COLLECTOR STATUS -->

<div class="tracking-card">

    <h5 class="fw-bold mb-3">

        <i class="fas fa-users"></i>

        Collector Status

    </h5>

    <div id="collectorList" class="text-secondary">
        Loading collectors...
    </div>

</div>


<script>

(function () {

    "use strict";

    // Prevent duplicate initialization
    if (window.ecoTrackInitialized) {
        return;
    }

    window.ecoTrackInitialized = true;

    const mapElement = document.getElementById("collectorMap");
    const listElement = document.getElementById("collectorList");
    const updatedElement = document.getElementById("trackingLastUpdated");
    const errorElement = document.getElementById("trackingError");

    if (!mapElement || !listElement || !updatedElement || !errorElement) {
        window.ecoTrackInitialized = false;
        console.error("Tracking elements are missing.");
        return;
    }

    // CHECK LEAFLET
    if (typeof L === "undefined") {

        listElement.textContent =
            "Leaflet map library did not load. Check your internet connection.";

        errorElement.textContent =
            "Map library unavailable. Reload the page after checking your connection.";

        errorElement.classList.remove("d-none");

        window.ecoTrackInitialized = false;

        return;
    }

    // INITIALIZE MAP
    const map = L.map("collectorMap").setView(
        [15.4802, 120.5979],
        12
    );

    const markerByCollector = {};

    let firstValidLocation = true;
    let requestInProgress = false;

    // OPENSTREETMAP
    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            attribution: "&copy; OpenStreetMap contributors",
            maxZoom: 19
        }
    ).addTo(map);

    // MAP SIZE FIX
    setTimeout(function () {
        map.invalidateSize();
    }, 300);


    // SHOW ERROR
    function showError(message) {

        errorElement.textContent = message;

        errorElement.classList.remove("d-none");

    }


    // CLEAR ERROR
    function clearError() {

        errorElement.textContent = "";

        errorElement.classList.add("d-none");

    }


    // CREATE COLLECTOR ROW
    function makeCollectorRow(collector) {

        const row = document.createElement("div");

        row.className = "collector-row";

        const left = document.createElement("div");

        const name = document.createElement("div");

        name.className = "collector-name";

        name.textContent =
            collector.fullname || ("Collector #" + collector.id);

        const coordinates = document.createElement("div");

        coordinates.className = "collector-coordinates";

        coordinates.textContent =
            Number(collector.latitude).toFixed(6) +
            ", " +
            Number(collector.longitude).toFixed(6);

        left.appendChild(name);

        left.appendChild(coordinates);

        const status = document.createElement("span");

        status.className = "badge tracking-status";

        if (Number(collector.is_online) === 1) {

            status.classList.add("bg-success");

            status.textContent = "Online";

        } else {

            status.classList.add("bg-secondary");

            status.textContent = "Offline";

        }

        row.appendChild(left);

        row.appendChild(status);

        // Click collector to locate on map
        row.style.cursor = "pointer";

        row.addEventListener("click", function () {

            const point = [
                Number(collector.latitude),
                Number(collector.longitude)
            ];

            map.setView(point, 17);

            if (markerByCollector[collector.id]) {
                markerByCollector[collector.id].openPopup();
            }

        });

        return row;

    }


    // FETCH COLLECTOR LOCATIONS
    async function loadCollectors() {

        if (requestInProgress) {
            return;
        }

        requestInProgress = true;

        try {

            const response = await fetch(
                new URL("get_collector_locations.php", window.location.href),
                {
                    method: "GET",
                    cache: "no-store",
                    headers: {
                        "Accept": "application/json"
                    }
                }
            );

            const responseText = await response.text();

            if (!response.ok) {

                throw new Error(
                    "API returned HTTP " +
                    response.status +
                    ": " +
                    responseText.substring(0, 250)
                );

            }

            let collectors;

            try {

                collectors = JSON.parse(responseText);

            } catch (parseError) {

                throw new Error(
                    "API did not return valid JSON. Response: " +
                    responseText.substring(0, 250)
                );

            }

            if (!Array.isArray(collectors)) {

                throw new Error(
                    collectors.error || "Unexpected API response."
                );

            }

            clearError();

            listElement.innerHTML = "";

            const receivedIds = new Set();

            let validCount = 0;

            collectors.forEach(function (collector) {

                const id = Number(collector.id);

                const lat = Number(collector.latitude);

                const lng = Number(collector.longitude);

                // VALIDATE GPS DATA
                if (
                    !Number.isInteger(id) ||
                    !Number.isFinite(lat) ||
                    !Number.isFinite(lng) ||
                    lat < -90 ||
                    lat > 90 ||
                    lng < -180 ||
                    lng > 180
                ) {

                    return;

                }

                validCount++;

                receivedIds.add(String(id));

                const point = [lat, lng];

                const safeName = String(
                    collector.fullname || "Collector"
                );

                // UPDATE EXISTING MARKER
                if (markerByCollector[id]) {

                    markerByCollector[id].setLatLng(point);

                } else {

                    markerByCollector[id] = L.marker(point).addTo(map);

                }

                // CREATE POPUP
                const popup = document.createElement("div");

                const popupName = document.createElement("strong");

                popupName.textContent = safeName;

                const popupStatus = document.createElement("div");

                popupStatus.textContent =
                    Number(collector.is_online) === 1
                    ? "Online"
                    : "Offline";

                const popupUpdated = document.createElement("small");

                popupUpdated.textContent =
                    "Last GPS update: " +
                    (collector.updated_at || "Unknown");

                popup.appendChild(popupName);

                popup.appendChild(popupStatus);

                popup.appendChild(popupUpdated);

                markerByCollector[id].bindPopup(popup);

                // ADD TO COLLECTOR LIST
                listElement.appendChild(
                    makeCollectorRow(collector)
                );

                // CENTER MAP ON FIRST COLLECTOR
                if (firstValidLocation) {

                    map.setView(point, 15);

                    firstValidLocation = false;

                }

            });


            // REMOVE OLD MARKERS
            Object.keys(markerByCollector).forEach(function (id) {

                if (!receivedIds.has(String(id))) {

                    map.removeLayer(markerByCollector[id]);

                    delete markerByCollector[id];

                }

            });


            // NO GPS RECORDS
            if (validCount === 0) {

                listElement.textContent =
                    "No collector GPS records found. Check whether collectors have shared their location.";

            }


            // LAST UPDATED
            updatedElement.textContent =
                "Last checked: " +
                new Date().toLocaleTimeString();

        } catch (error) {

            console.error(
                "Collector tracking error:",
                error
            );

            showError(error.message);

            listElement.textContent =
                "Unable to load collector locations. See the error message above.";

        } finally {

            requestInProgress = false;

        }

    }


    // INITIAL LOAD
    loadCollectors();


    // AUTOMATIC REFRESH EVERY 5 SECONDS
    const trackingInterval = setInterval(
        loadCollectors,
        5000
    );


    // RESPONSIVE MAP
    window.addEventListener("resize", function () {

        map.invalidateSize();

    });

})();

</script>
