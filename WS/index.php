<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShowroomSumut - Semantic Web KB</title>
    <link rel="stylesheet" href="style.css">
    <meta property="og:title" content="ShowroomSumut - Knowledge Base Kendaraan" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="http://localhost/WS/index.php" />
    <meta property="og:image" content="http://localhost/WS/hero-image.jpg" />
    <meta property="og:description" content="Sistem Informasi Showroom Kendaraan Sumatera Utara berbasis Web Semantik." />
</head>
<body>

    <!-- NAVBAR ALA SHOPEE (LOGO, SEARCH BAR, TOMBOL FILTER, MENU) -->
    <header>
        <h1>ShowroomSumut<span>Knowledge Base</span></h1>
        
        <!-- Search Bar di dalam Navbar -->
        <div class="nav-search-container">
            <form action="daftar.php" method="GET" class="search-bar-nav" id="searchForm">
                <input type="text" name="keyword" placeholder="Cari showroom atau kendaraan (misal: Auto2000)...">
                
                <!-- Logo / Tombol Filter -->
                <button type="button" class="filter-toggle-btn" id="filterToggleBtn" title="Filter Pencarian">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M1.5 1.5A.5.5 0 0 1 2 1h12a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.128.334L10 8.692V13.5a.5.5 0 0 1-.342.474l-3 1A.5.5 0 0 1 6 14.5V8.692L1.628 3.834A.5.5 0 0 1 1.5 3.5v-2zm1 .5v1.308l4.372 4.858A.5.5 0 0 1 7 8.5v5.306l2-.666V8.5a.5.5 0 0 1 .128-.334L13.5 3.308V2h-11z"/>
                    </svg>
                    Filter
                </button>

                <button type="submit" class="submit-search">Cari</button>

                <!-- Panel Dropdown Filter -->
                <div class="filter-dropdown-panel" id="filterDropdown">
                    <select name="kategori" title="Kategori">
                        <option value="">Semua Kategori</option>
                        <option value="mobil">Mobil</option>
                        <option value="motor">Motor</option>
                    </select>

                    <select name="merek" title="Merek Kendaraan">
                        <option value="">Semua Merek</option>
                        <option value="Dealer Aion">Aion</option>
                        <option value="Dealer BMW">BMW</option>
                        <option value="Dealer BYD">BYD</option>
                        <option value="Dealer Chery">Chery</option>
                        <option value="Dealer Chevrolet">Chevrolet</option>
                        <option value="Dealer Daihatsu">Daihatsu</option>
                        <option value="Dealer Datsun">Datsun</option>
                        <option value="Dealer DFSK">DFSK</option>
                        <option value="Dealer Ford">Ford</option>
                        <option value="Dealer Honda">Honda</option>
                        <option value="Dealer Honda Wing">Honda Wing</option>
                        <option value="Dealer Hyundai">Hyundai</option>
                        <option value="Dealer Isuzu">Isuzu</option>
                        <option value="Dealer Kawasaki">Kawasaki</option>
                        <option value="Dealer Mazda">Mazda</option>
                        <option value="Dealer Mercedes-Benz">Mercedes-Benz</option>
                        <option value="Dealer MG">MG</option>
                        <option value="Dealer Mitsubishi">Mitsubishi</option>
                        <option value="Dealer Nissan">Nissan</option>
                        <option value="Dealer Suzuki">Suzuki</option>
                        <option value="Dealer Toyota">Toyota</option>
                        <option value="Dealer Vespa">Vespa</option>
                        <option value="Dealer Wuling">Wuling</option>
                        <option value="Dealer Yamaha">Yamaha</option>
                    </select>
                    
                    <select name="lokasi" title="Lokasi Wilayah">
                        <option value="">Semua Lokasi</option>
                        <option value="Asahan">Asahan</option>
                        <option value="Batu Bara">Batu Bara</option>
                        <option value="Binjai">Binjai</option>
                        <option value="Dairi">Dairi</option>
                        <option value="Deli Serdang">Deli Serdang</option>
                        <option value="Gunungsitoli">Gunungsitoli</option>
                        <option value="Karo">Karo</option>
                        <option value="Labuhanbatu">Labuhanbatu</option>
                        <option value="Labuhanbatu Selatan">Labuhanbatu Selatan</option>
                        <option value="Labuhanbatu Utara">Labuhanbatu Utara</option>
                        <option value="Langkat">Langkat</option>
                        <option value="Mandailing Natal">Mandailing Natal</option>
                        <option value="Medan">Medan</option>
                        <option value="Nias Selatan">Nias Selatan</option>
                        <option value="Padang Lawas">Padang Lawas</option>
                        <option value="Padang Lawas Utara">Padang Lawas Utara</option>
                        <option value="Padangsidimpuan">Padangsidimpuan</option>
                        <option value="Pematangsiantar">Pematangsiantar</option>
                        <option value="Samosir">Samosir</option>
                        <option value="Serdang Bedagai">Serdang Bedagai</option>
                        <option value="Sibolga">Sibolga</option>
                        <option value="Simalungun">Simalungun</option>
                        <option value="Tapanuli">Tapanuli</option>
                        <option value="Tapanuli Tengah">Tapanuli Tengah</option>
                        <option value="Tapanuli Utara">Tapanuli Utara</option>
                        <option value="Tebing Tinggi">Tebing Tinggi</option>
                        <option value="Toba">Toba</option>
                    </select>

                    <select name="buka24jam" title="Jam Operasional">
                        <option value="">Semua Jam Operasional</option>
                        <option value="24 jam">Buka 24 Jam Saja</option>
                    </select>

                    <select name="rating_filter" title="Filter Rating">
                        <option value="">Semua Rating</option>
                        <option value="atas">Rating &ge; 4.5</option>
                        <option value="bawah">Rating &lt; 4.5</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- Menu Navigasi (Eksplorasi Data & Export RDF Dihilangkan sesuai permintaan) -->
        <nav>
            <a href="index.php" style="color: var(--primary-blue);">Beranda</a>
        </nav>
    </header>

    <section class="hero">
        <h2>Eksplorasi Data<br>Kendaraan Sumut.</h2>
        <p>Sistem basis pengetahuan (Knowledge Base) terintegrasi menggunakan teknologi Web Semantik untuk direktori showroom di Sumatera Utara.</p>
    </section>

    <div class="section-title">
        <h3>Tentang Proyek</h3>
        <h2>Tugas Besar Web Semantik</h2>
        <p style="color: var(--text-muted); margin-top: 15px; max-width: 600px; margin-left: auto; margin-right: auto;">
            Sistem terintegrasi (Knowledge Base) berbasis file RDF/Turtle, dikelola menggunakan Apache Jena Fuseki.
        </p>
    </div>

    <footer>
        <p>&copy; 2026 Tugas Besar Web Semantik - Kelas KOM B (Kelompok Bhita, Nadin, Nepah, Jepin, Dwik).</p>
    </footer>

    <!-- Script sederhana untuk membuka/menutup panel filter saat tombol filter diklik -->
    <script>
        const filterToggleBtn = document.getElementById('filterToggleBtn');
        const filterDropdown = document.getElementById('filterDropdown');

        filterToggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            filterDropdown.classList.toggle('active');
        });

        // Tutup dropdown jika klik di luar area form pencarian
        document.addEventListener('click', function(e) {
            if (!document.getElementById('searchForm').contains(e.target)) {
                filterDropdown.classList.remove('active');
            }
        });
    </script>
</body>
</html>