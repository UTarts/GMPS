document.addEventListener('DOMContentLoaded', function() {
    // 1. Inject the Modal HTML into the body
    const modalHTML = `
        <div id="global-lightbox" class="lightbox-modal">
            <span class="close-btn">&times;</span>
            <a id="download-btn" href="#" download class="download-btn">
                <span class="material-symbols-outlined">download</span>
            </a>
            <div class="lightbox-content-wrapper">
                <img id="lightbox-img" src="" alt="Full View">
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHTML);

    const modal = document.getElementById('global-lightbox');
    const modalImg = document.getElementById('lightbox-img');
    const downloadLink = document.getElementById('download-btn');
    const closeBtn = modal.querySelector('.close-btn');

    // 2. Function to open modal
    function openLightbox(src) {
        modalImg.src = src;
        downloadLink.href = src; // Set download link to image source
        modal.classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent background scroll
    }

    // 3. Function to close modal
    function closeLightbox() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        setTimeout(() => { modalImg.src = ''; }, 300); // Clear src after fade out
    }

    // 4. Attach Event Listeners to all expandable images
    document.querySelectorAll('.expandable-image').forEach(img => {
        img.style.cursor = 'zoom-in';
        img.addEventListener('click', function() {
            openLightbox(this.src);
        });
    });

    // 5. Close events
    closeBtn.addEventListener('click', closeLightbox);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeLightbox();
    });
    
    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeLightbox();
    });
});