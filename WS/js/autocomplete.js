
document.addEventListener("DOMContentLoaded", () => {
    const input = document.getElementById("keywordSearch");
    const dropdown = document.getElementById("searchSuggestions");

    if (!input || !dropdown) return;

    // Daftar sementara, nanti bisa diambil dari database
    const daftarSaran = [
        "Honda Motor",
        "Honda Mobil",
        "Toyota",
        "Yamaha",
        "Suzuki",
        "Daihatsu",
        "Hyundai",
        "Kawasaki",
        "Nissan",
        "Mazda",
        "Vario",
        "Beat",
        "Scoopy",
        "PCX",
        "NMAX",
        "Aerox",
        "Brio",
        "Avanza",
        "Innova"
    ];

    input.addEventListener("input", () => {
        const keyword = input.value.trim().toLowerCase();
        dropdown.replaceChildren();

        if (keyword.length < 2) {
            dropdown.classList.remove("active");
            return;
        }

        const hasil = daftarSaran
            .filter(item => item.toLowerCase().includes(keyword))
            .slice(0, 5);

        hasil.forEach(item => {
            const tombol = document.createElement("button");
            tombol.type = "button";
            tombol.className = "search-suggestion-item";
            tombol.textContent = item;
          
            tombol.addEventListener("click", () => {
                input.value = item;

                dropdown.classList.remove("active");

                const form = document.getElementById("searchFilterForm");

                if (form) {
                    form.requestSubmit();
                }
            });


            dropdown.appendChild(tombol);
        });

        dropdown.classList.toggle("active", hasil.length > 0);
    });

    document.addEventListener("click", (event) => {
        if (!event.target.closest(".search-wrapper")) {
            dropdown.classList.remove("active");
        }
    });
});