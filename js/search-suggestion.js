/**
 * Smart Live Search Module for Mộc Trà
 * Features: Debounce, Keyword Suggestion, Product Preview, Smooth Overlay
 */

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('.nav-search input[name="q"]');
    const suggestionsBox = document.querySelector('.search-suggestions');
    const overlay = document.querySelector('.search-overlay');
    
    let debounceTimer;

    if (!searchInput || !suggestionsBox) return;

    function debounce(func, delay) {
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => func.apply(context, args), delay);
        };
    }

    const performSearch = function() {
        const query = searchInput.value.trim();
        
        if (query.length < 2) {
            hideSuggestions();
            return;
        }

        fetch(`api/search_suggest.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                renderSuggestions(data, query);
            })
            .catch(error => {
                console.error('Search error:', error);
                hideSuggestions();
            });
    };

    function renderSuggestions(data, query) {
        const { keywords, products, total_products } = data;
        let html = '';

        // 1. Phần Gợi ý từ khóa
        if (keywords && keywords.length > 0) {
            html += '<div class="suggestion-section">';
            html += '<div class="suggestion-group-title">Gợi ý từ khóa</div>';
            keywords.forEach(key => {
                html += `
                    <a href="products.php?q=${encodeURIComponent(key)}" class="keyword-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span>${key}</span>
                    </a>
                `;
            });
            html += '</div>';
        }

        // 2. Phần Sản phẩm gợi ý
        html += '<div class="suggestion-section" style="padding-bottom: 0;">';
        html += '<div class="suggestion-group-title">Sản phẩm tìm thấy</div>';
        
        if (products && products.length > 0) {
            products.forEach(item => {
                html += `
                    <a href="${item.url}" class="suggestion-item">
                        <div class="suggestion-info">
                            <h4 class="suggestion-name">${item.name}</h4>
                            <div class="suggestion-pricing">
                                <span class="suggestion-price">${item.price}</span>
                                ${item.price_old ? `<span class="suggestion-price-old">${item.price_old}</span>` : ''}
                            </div>
                        </div>
                        <div class="suggestion-img-wrapper">
                            <img src="${item.image}" class="suggestion-img" alt="${item.name}" onerror="this.src='images/traden.png'">
                        </div>
                    </a>
                `;
            });
            
            // 3. Nút xem thêm
            const remaining = total_products - products.length;
            if (remaining > 0) {
                html += `
                    <a href="products.php?q=${encodeURIComponent(query)}" class="suggestion-view-more">
                        Xem thêm ${remaining} sản phẩm
                    </a>
                `;
            }
        } else {
            html += `<div style="padding: 16px; text-align: center; color: #9ca3af; font-size: 13px;">
                        Không tìm thấy sản phẩm cho "${query}"
                    </div>`;
        }
        html += '</div>';

        suggestionsBox.innerHTML = html;
        showSuggestions();
    }

    function showSuggestions() {
        suggestionsBox.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function hideSuggestions() {
        suggestionsBox.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    searchInput.addEventListener('input', debounce(performSearch, 300));
    
    searchInput.addEventListener('focus', function() {
        if (this.value.trim().length >= 2) {
            if (!suggestionsBox.innerHTML.trim()) {
                performSearch();
            } else {
                showSuggestions();
            }
        }
    });

    // Đóng khi click overlay hoặc phím Esc
    overlay.addEventListener('click', hideSuggestions);
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') hideSuggestions();
    });

    // Đóng khi click ra ngoài vùng search-wrapper
    document.addEventListener('click', function(e) {
        const isClickInside = searchInput.closest('.nav-search-wrapper').contains(e.target);
        if (!isClickInside) {
            hideSuggestions();
        }
    });
});
