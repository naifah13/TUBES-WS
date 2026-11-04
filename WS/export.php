<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Data RDF - Semantic Web KB</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- NAVBAR / HEADER -->
    <header>
        <h1>ShowroomSumut<span style="color: var(--text-dark); font-size: 16px; margin-left: 5px;">Knowledge Base</span></h1>
        <nav>
            <a href="index.php">Beranda</a>
            <a href="daftar.php">Eksplorasi Data</a>
            <a href="export.php" style="color: var(--primary-blue);">Export RDF</a>
        </nav>
    </header>

    <!-- HEADER HALAMAN -->
    <div class="section-title">
        <h3>Linked Open Data</h3>
        <h2>Unduh Dataset RDF</h2>
        <p style="color: var(--text-muted); margin-top: 15px; max-width: 600px; margin-left: auto; margin-right: auto;">
            Akses langsung ke raw data semantic web dari knowledge base showroom Sumatera Utara.
        </p>
    </div>

    <!-- KONTEN EXPORT -->
    <div class="grid-container" style="max-width: 800px; margin: 0 auto;">
        
        <div class="card" style="text-align: left; padding: 40px;">
            <h3 style="color: var(--primary-blue); font-size: 24px; margin-bottom: 10px;">File RDF (Turtle Format)</h3>
            <p style="color: var(--text-muted); margin-bottom: 25px;">
                File ini berisi seluruh triple entitas, relasi, dan atribut dari showroom kendaraan, sesuai dengan struktur ontologi yang telah dibangun menggunakan format .ttl.
            </p>
            
            <div style="background: var(--bg-light); padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 30px;">
                <p><strong>Nama File:</strong> showroomSumut.ttlux</p>
                <p><strong>Format:</strong> Turtle / RDF</p>
                <p><strong>Status:</strong> Terhubung dengan SPARQL Endpoint</p>
            </div>

            <div style="display: flex; gap: 15px;">
                <!-- Tombol ini langsung mengarah ke file data kalian untuk diunduh/dilihat -->
                <a href="showroomSumut.ttlux" target="_blank" class="btn-outline" style="margin-top: 0; background-color: var(--primary-blue); color: white;">
                    Lihat Raw Data
                </a>
                <a href="showroomSumut.ttlux" download class="btn-outline" style="margin-top: 0;">
                    Unduh File .ttl
                </a>
            </div>
        </div>

    </div>

    <!-- FOOTER -->
    <footer style="margin-top: 80px;">
        <p>&copy; 2026 Tugas Besar Web Semantik - Kelas KOM B (Kelompok Bhita dkk).</p>
    </footer>

</body>
</html>