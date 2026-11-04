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

    <header>
        <h1>ShowroomSumut<span style="color: var(--text-dark); font-size: 16px; margin-left: 5px;">Knowledge Base</span></h1>
        <nav>
            <a href="index.php" style="color: var(--primary-blue);">Beranda</a>
            <a href="daftar.php">Eksplorasi Data</a>
            <a href="export.php">Export RDF</a>
        </nav>
    </header>

    <section class="hero">
        <h2>Eksplorasi Data<br>Kendaraan Sumut.</h2>
        <p>Sistem basis pengetahuan (Knowledge Base) terintegrasi menggunakan teknologi Web Semantik untuk direktori showroom di Sumatera Utara.</p>
    </section>

    <!-- FORM PENCARIAN SUPER LENGKAP -->
    <form action="daftar.php" method="GET" class="search-bar">
        
        <input type="text" name="keyword" placeholder="Nama (misal: Auto2000)">
        
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
        
        <!-- Lokasi yang sudah dipisah secara spesifik -->
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

        <!-- Penamaan opsi Jam Operasional yang lebih rapi -->
        <select name="buka24jam" title="Jam Operasional">
            <option value="">Semua Jam Operasional</option>
            <option value="24 jam">Buka 24 Jam Saja</option>
        </select>

        <!-- Opsi Rating 4.5 yang baru -->
        <select name="rating_filter" title="Filter Rating">
            <option value="">Semua Rating</option>
            <option value="atas">Rating &ge; 4.5</option>
            <option value="bawah">Rating &lt; 4.5</option>
        </select>

        <button type="submit" style="grid-column: span 2;">Mulai Penelusuran Semantik</button>
    </form>

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

</body>
</html>