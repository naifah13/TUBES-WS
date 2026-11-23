<?php
declare(strict_types=1);

/*
 * Asisten Vroom: chatbot showroom offline.
 * - Tanpa API layanan AI dan tanpa Fuseki: data dibaca langsung dari file .ttl lokal
 *   lewat chatbot/rdf-store.php, pertanyaan dipahami lewat aturan di chatbot/nlu.php.
 * - POST application/json → selalu membalas JSON, termasuk saat terjadi error PHP.
 */

$vroomBotEmbedded = ($vroomBotEmbedded ?? false) === true;

if (!$vroomBotEmbedded && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ob_start();
    $GLOBALS['vroomChatSelesai'] = false;

    $kirim = static function (int $status, array $body): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        $body += ['pesan' => '', 'intent' => 'galat', 'hasil' => [], 'total' => 0, 'ada_lagi' => false,
            'minta_lokasi' => false, 'konteks' => null, 'saran' => [], 'catatan' => []];
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        $GLOBALS['vroomChatSelesai'] = true;
    };

    // Error fatal (misalnya memori habis) tetap dibalas JSON, bukan HTML.
    register_shutdown_function(static function () use ($kirim): void {
        $error = error_get_last();
        if (!$GLOBALS['vroomChatSelesai'] && $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $kirim(500, ['pesan' => 'Terjadi kesalahan di server chatbot. Cek log error PHP di Laragon.']);
        }
    });

    try {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            $kirim(415, ['pesan' => 'Format permintaan harus JSON.']);
            exit;
        }
        $input = file_get_contents('php://input', false, null, 0, 8193);
        if ($input === false || strlen($input) > 8192) {
            $kirim(413, ['pesan' => 'Permintaan terlalu panjang (maksimal 8 KB). Coba pesan yang lebih singkat.']);
            exit;
        }
        try {
            $request = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $request = null;
        }
        if (!is_array($request)) {
            $kirim(400, ['pesan' => 'Format permintaan tidak terbaca. Coba kirim ulang pesanmu.']);
            exit;
        }

        require_once __DIR__ . '/chatbot/nlu.php';
        $kirim(200, vroomChatReply($request));
    } catch (Throwable $error) {
        error_log('[Vroom chatbot] ' . get_class($error) . ': ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine());
        $pesan = $error instanceof RuntimeException && str_contains($error->getMessage(), '.ttl')
            ? $error->getMessage()
            : 'Terjadi kesalahan di server chatbot. Coba lagi sebentar lagi.';
        $kirim(500, ['pesan' => $pesan]);
    }
    exit;
}

if (!$vroomBotEmbedded) {
    header('Location: index.php#vroomBot', true, 302);
    exit;
}
?>

<div class="vroom-bot" id="vroomBotWidget">
    <button class="vb-launcher" id="chat-launcher" type="button" aria-controls="chat-panel" aria-expanded="false" aria-label="Buka vroomBot">
        <span class="vb-launcher-icon" aria-hidden="true"><span class="vb-chat-glyph"></span><span class="vb-launcher-x">×</span></span>
        <span>vroomBot</span>
    </button>
    <section class="vb-panel" id="chat-panel" role="dialog" aria-labelledby="chat-panel-title" hidden>
        <header class="vb-header">
            <span class="vb-mark" aria-hidden="true"><img src="images/vroom.svg" alt="" width="28" height="28"></span>
            <div class="vb-title">
                <h2 id="chat-panel-title">vroomBot</h2>
                <div class="chat-loc" id="chat-loc">
                    <span class="vb-location-text" id="chat-loc-text" role="status">Lokasi nonaktif</span>
                    <button class="vb-location-button" type="button" id="chat-loc-btn">Aktifkan</button>
                </div>
            </div>
            <div class="vb-actions">
                <button class="vb-header-action" type="button" id="chat-reset" aria-label="Mulai chat baru" title="Chat baru"><span aria-hidden="true">↻</span></button>
                <button class="vb-header-action" type="button" id="chat-close" aria-label="Tutup vroomBot" title="Tutup"><span aria-hidden="true">×</span></button>
            </div>
        </header>
        <div class="chat-log" id="chat-log" role="log" aria-label="Percakapan" aria-live="polite">
            <div class="vb-welcome" id="chat-welcome">
                <span class="vb-welcome-mark" aria-hidden="true"><img src="images/vroom.svg" alt="" width="40" height="40"></span>
                <h3>Mau cari showroom?</h3>
                <p>Tanya berdasarkan merek, model, atau kota di Sumatera Utara.</p>
                <div class="vb-quick" role="group" aria-label="Pertanyaan cepat">
                    <button class="chat-choice" type="button" data-question="Cari showroom terdekat">Terdekat</button>
                    <button class="chat-choice" type="button" data-question="Showroom mana yang masih buka?">Masih buka</button>
                    <button class="chat-choice" type="button" data-question="Tampilkan showroom dengan rating tertinggi">Rating tertinggi</button>
                </div>
            </div>
        </div>
        <form class="chat-form" id="chat-form">
            <label class="chat-sr" for="chat-input">Pesan untuk vroomBot</label>
            <div class="vb-composer">
                <textarea class="vb-input" id="chat-input" name="pesan" rows="2" maxlength="400" placeholder="Ada yang ingin Anda tanyakan? vroomBot siap membantu!" required></textarea>
                <button class="chat-send" id="chat-send" type="submit" aria-label="Kirim pesan" title="Kirim"><span class="vb-send-icon" aria-hidden="true"></span></button>
            </div>
            <p class="chat-sr" id="chat-status" role="status"></p>
        </form>
    </section>
</div>
<script>
    (() => {
        const root = document.getElementById('vroomBotWidget');
        const launcher = root.querySelector('#chat-launcher');
        const panel = root.querySelector('#chat-panel');
        const close = root.querySelector('#chat-close');
        const form = root.querySelector('#chat-form');
        const log = root.querySelector('#chat-log');
        const input = root.querySelector('#chat-input');
        const statusLine = root.querySelector('#chat-status');
        const send = root.querySelector('#chat-send');
        const reset = root.querySelector('#chat-reset');
        const locChip = root.querySelector('#chat-loc');
        const locText = root.querySelector('#chat-loc-text');
        const locButton = root.querySelector('#chat-loc-btn');
        const examples = [...root.querySelectorAll('[data-question]')];
        const welcome = root.querySelector('#chat-welcome');
        const LOC_KEY = 'vroomLokasi';
        const LOC_MAX_AGE = 30 * 60 * 1000;
        const NEAR_WORDS = /\b(terdekat|dekat|deket|dkt|sekitar|sini|nearby|near me)\b/i;

        let context = null;
        let history = [];
        let locationData = null;
        let locationState = 'idle'; // idle | meminta | aktif | ditolak | tidak-didukung | gagal
        let busy = false;
        let locationInitialized = false;
        let previousScroll = 0;

        function setOpen(value) {
            if (!value) previousScroll = log.scrollTop;
            panel.hidden = !value;
            launcher.setAttribute('aria-expanded', String(value));
            launcher.setAttribute('aria-label', value ? 'Tutup vroomBot' : 'Buka vroomBot');
            if (value) {
                log.scrollTop = previousScroll;
                close.focus({ preventScroll: true });
                if (!locationInitialized) {
                    locationInitialized = true;
                    initLocation();
                }
            } else {
                launcher.focus({ preventScroll: true });
            }
        }

        launcher.addEventListener('click', () => setOpen(panel.hidden));
        close.addEventListener('click', () => setOpen(false));
        root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                event.preventDefault();
                setOpen(false);
            }
        });

        function item(tag, text, className) {
            const element = document.createElement(tag);
            element.textContent = text;
            element.className = className || '';
            return element;
        }

        function showPromptIfNeeded(prompt, typing) {
            const viewport = log.getBoundingClientRect();
            if (prompt.getBoundingClientRect().bottom > viewport.bottom) {
                // Geser seperlunya agar pesan yang baru dikirim dan indikator terlihat.
                log.scrollTop += typing.getBoundingClientRect().bottom - viewport.bottom + 10;
            }
        }

        function createTypingIndicator() {
            const indicator = item('div', '', 'chat-typing');
            indicator.setAttribute('aria-hidden', 'true');
            const dots = item('span', '', 'chat-typing-dots');
            for (let i = 0; i < 3; i++) dots.append(item('i', ''));
            const label = item('span', 'vroomBot sedang mengetik…');
            indicator.append(dots, label);
            return { indicator, label };
        }

        function showMessage(text, author = '', notes = [], target = log) {
            welcome.hidden = true;
            const message = item('p', text, 'chat-message' + (author ? ' chat-message--' + author : ''));
            for (const note of notes) {
                if (!note || note.startsWith('Jarak dihitung garis lurus dari lokasimu.')
                    || note.startsWith('Status buka/tutup dihitung menurut jadwal harian (WIB).')) {
                    continue;
                }
                message.append(item('small', note, 'chat-source'));
            }
            target.append(message);
            return message;
        }

        function remember(message, answer) {
            const recent = (text) => Array.from(text).slice(0, 300).join('');
            history = [...history, { peran: 'pengguna', teks: recent(message) }, { peran: 'asisten', teks: recent(answer) }].slice(-4);
        }

        function resultLink(title, href, external = false) {
            const link = item('a', title);
            link.href = href;
            if (external) {
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
            }
            return link;
        }

        /* ---------- Lokasi ---------- */

        function setLocationState(state, detail = '') {
            locationState = state;
            locChip.classList.toggle('chat-loc--aktif', state === 'aktif');
            locChip.classList.toggle('chat-loc--ditolak', state === 'ditolak' || state === 'gagal');
            const labels = {
                idle: 'Lokasi nonaktif',
                meminta: 'Meminta lokasi…',
                aktif: 'Lokasi aktif' + detail,
                ditolak: 'Izin lokasi ditolak',
                'tidak-didukung': 'Lokasi tidak tersedia',
                gagal: 'Lokasi tidak ditemukan',
            };
            locText.textContent = labels[state] || labels.idle;
            locButton.hidden = state === 'aktif' || state === 'meminta' || state === 'tidak-didukung';
            locText.title = locText.textContent;
            locButton.textContent = state === 'idle' ? 'Aktifkan' : 'Coba lagi';
        }

        function accuracyLabel(meters) {
            return Number.isFinite(meters) ? ' (±' + Math.round(meters).toLocaleString('id-ID') + ' m)' : '';
        }

        function saveLocation(data) {
            try { sessionStorage.setItem(LOC_KEY, JSON.stringify(data)); } catch { /* storage tidak tersedia */ }
        }

        function loadLocation() {
            try {
                const data = JSON.parse(sessionStorage.getItem(LOC_KEY) || 'null');
                if (data && Number.isFinite(data.lat) && Number.isFinite(data.lon) && Date.now() - data.waktu < LOC_MAX_AGE) return data;
            } catch { /* abaikan */ }
            return null;
        }

        let locationPromise = null;
        function requestLocation() {
            if (locationPromise) return locationPromise;
            locationPromise = new Promise((resolve) => {
                if (!('geolocation' in navigator)) {
                    setLocationState('tidak-didukung');
                    resolve(false);
                    return;
                }
                setLocationState('meminta');
                navigator.geolocation.getCurrentPosition(
                    ({ coords }) => {
                        locationData = { lat: coords.latitude, lon: coords.longitude, akurasi_m: coords.accuracy, waktu: Date.now() };
                        saveLocation(locationData);
                        setLocationState('aktif', accuracyLabel(coords.accuracy));
                        resolve(true);
                    },
                    (error) => {
                        setLocationState(error && error.code === 1 ? 'ditolak' : 'gagal');
                        resolve(false);
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 },
                );
            }).finally(() => { locationPromise = null; });
            return locationPromise;
        }

        async function initLocation() {
            const stored = loadLocation();
            if (stored) {
                locationData = stored;
                setLocationState('aktif', accuracyLabel(stored.akurasi_m));
                return;
            }
            setLocationState('idle');
            let permission = 'prompt';
            try {
                if (navigator.permissions) permission = (await navigator.permissions.query({ name: 'geolocation' })).state;
            } catch { /* Permissions API tidak tersedia */ }
            if (permission === 'denied') {
                setLocationState('ditolak');
                return;
            }
            if (permission === 'granted') requestLocation();
        }

        locButton.addEventListener('click', async () => {
            if (busy) return;
            const ok = await requestLocation();
            if (!ok && locationState === 'ditolak') {
                showMessage('Izin lokasi ditolak browser. Kamu bisa mengizinkannya lewat ikon gembok di address bar, atau cukup sebut nama kota, misalnya "showroom di Binjai".');
            }
        });

        /* ---------- Hasil ---------- */

        function locationPayload() {
            return locationData ? { lat: locationData.lat, lon: locationData.lon, akurasi_m: locationData.akurasi_m ?? null } : null;
        }

        function timePayload() {
            const now = new Date();
            let zone = '';
            try { zone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch { /* abaikan */ }
            return { iso: now.toISOString(), offset_menit: now.getTimezoneOffset(), zona: zone };
        }

        function showBranches(branches, target) {
            const list = item('ul', '', 'chat-result');
            for (const [index, branch] of branches.entries()) {
                const card = item('li', '', 'chat-card');
                card.style.setProperty('--vb-card-order', Math.min(index, 4));
                const head = item('div', '', 'chat-card-head');
                head.append(item('strong', branch.nama), item('span', branch.status_label || '', 'chat-badge chat-badge--' + (branch.status || 'tidak-diketahui')));
                card.append(head);

                const kind = [
                    Array.isArray(branch.merek) ? branch.merek.join(' · ') : '',
                    branch.kategori,
                    branch.wilayah,
                ].filter(Boolean).join(' · ');
                if (kind) card.append(item('p', kind));

                const score = [
                    branch.rating_teks ? '★ ' + branch.rating_teks : '',
                    branch.jarak_teks || '',
                ].filter(Boolean).join(' · ');
                if (score) card.append(item('p', score));

                let hours = '🕘 Jam operasional belum tersedia';
                if (branch.jam_tersedia) hours = '🕘 Jam operasional: ' + branch.jam + (branch.status === '24jam' ? '' : ' WIB');
                else if (branch.jam) hours = '🕘 Jam operasional tertulis: ' + branch.jam;
                const hoursLine = item('p', hours, 'chat-card-hours');
                if (branch.jam_tersedia && branch.status_teks && branch.status !== '24jam') hoursLine.append(item('span', branch.status_teks, 'chat-card-state'));
                card.append(hoursLine);

                if (branch.alamat || branch.telepon) {
                    const contact = item('details', '', 'chat-card-contact');
                    contact.append(item('summary', branch.alamat && branch.telepon ? 'Alamat & telepon' : branch.alamat ? 'Alamat' : 'Telepon'));
                    if (branch.alamat) contact.append(item('p', branch.alamat));
                    if (branch.telepon) contact.append(item('p', branch.telepon));
                    card.append(contact);
                }

                const links = item('div', '', 'chat-card-links');
                links.append(resultLink('Lihat detail', branch.detail, true));
                if (branch.rute) links.append(resultLink('Buka rute', branch.rute, true));
                card.append(links);
                list.append(card);
            }
            target.append(list);
        }

        function showButtons(labels, target, className = 'chat-suggest') {
            if (!labels.length) return;
            const row = item('div', '', className);
            for (const label of labels) {
                const button = item('button', label, 'chat-choice');
                button.type = 'button';
                button.addEventListener('click', () => ask(label));
                row.append(button);
            }
            target.append(row);
        }

        function offerLocation(message, target) {
            const button = item('button', 'Pakai lokasi saya', 'chat-location');
            button.type = 'button';
            button.addEventListener('click', async () => {
                if (busy) return;
                button.disabled = true;
                const ok = await requestLocation();
                button.remove();
                if (ok) ask(message, false);
                else showMessage('Lokasi belum bisa diambil. Tulis nama kota atau kabupaten, misalnya "showroom di Medan".');
            });
            target.append(button);
        }

        function setBusy(value, message = '') {
            busy = value;
            send.disabled = value;
            reset.disabled = value;
            for (const button of examples) button.disabled = value;
            for (const button of log.querySelectorAll('button')) button.disabled = value;
            statusLine.textContent = message;
        }

        async function ask(message, echo = true) {
            message = message.trim();
            if (busy || !message) return;

            const active = document.activeElement;
            const focusOnPrompt = active === send || welcome.contains(active)
                || (log.contains(active) && active.closest('.chat-suggest, .chat-location'));
            for (const extra of log.querySelectorAll('.chat-location, .chat-suggest')) extra.remove();

            const turn = item('div', '', 'chat-turn');
            log.append(turn);
            const prompt = echo ? showMessage(message, 'user', [], turn) : null;
            const { indicator, label } = createTypingIndicator();
            turn.append(indicator);
            const reply = item('div', '', 'chat-reply');
            const minimumTypingTime = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 500;
            const typingDelay = new Promise((resolve) => setTimeout(resolve, minimumTypingTime));
            if (prompt) {
                if (focusOnPrompt) {
                    prompt.tabIndex = -1;
                    prompt.focus({ preventScroll: true });
                }
                showPromptIfNeeded(prompt, indicator);
            }

            setBusy(true, label.textContent);
            try {
                // Pertanyaan lokasi boleh meminta izin sebelum pencarian dimulai.
                if (!locationData && NEAR_WORDS.test(message) && ['idle', 'gagal', 'meminta'].includes(locationState)) {
                    label.textContent = 'Meminta izin lokasi…';
                    statusLine.textContent = label.textContent;
                    await requestLocation();
                    label.textContent = 'vroomBot sedang mengetik…';
                    statusLine.textContent = label.textContent;
                }
                const response = await fetch('vroom-chatbot.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        pesan: message,
                        konteks: context,
                        lokasi: locationPayload(),
                        waktu: timePayload(),
                        riwayat: history,
                    }),
                });
                const type = response.headers.get('content-type') || '';
                if (!type.includes('application/json')) {
                    const raw = (await response.text()).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
                    showMessage('Server mengirim respons yang bukan JSON (HTTP ' + response.status + '). Cek log error PHP di Laragon.', 'error', raw ? ['Cuplikan: ' + raw] : [], reply);
                    return;
                }
                const data = await response.json();
                const answer = data.pesan || 'Coba ulangi permintaanmu.';
                showMessage(answer, response.ok ? '' : 'error', Array.isArray(data.catatan) ? data.catatan : [], reply);
                if (!response.ok) return;

                remember(message, answer);
                if (data.konteks) context = data.konteks;
                if (Array.isArray(data.hasil) && data.hasil.length) showBranches(data.hasil, reply);
                if (data.ada_lagi) showButtons(['Tampilkan lainnya'], reply, 'chat-suggest chat-more');
                if (data.minta_lokasi && !locationData && locationState !== 'tidak-didukung') offerLocation(message, reply);
                if (Array.isArray(data.saran)) showButtons(data.saran.slice(0, 3), reply);
            } catch {
                showMessage('Server chatbot belum bisa dihubungi. Periksa apakah server PHP masih berjalan.', 'error', [], reply);
            } finally {
                await typingDelay;
                indicator.replaceWith(reply);
                setBusy(false);
            }
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const message = input.value;
            if (busy || !message.trim()) return;
            input.value = '';
            ask(message);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                if (!busy && input.value.trim()) form.requestSubmit();
            }
        });
        for (const button of examples) {
            button.addEventListener('click', () => ask(button.dataset.question));
        }
        reset.addEventListener('click', () => {
            context = null;
            history = [];
            input.value = '';
            log.replaceChildren(welcome);
            welcome.hidden = false;
            log.scrollTop = 0;
            input.focus({ preventScroll: true });
        });

        if (window.location.hash === '#vroomBot') setOpen(true);
        })();
</script>
