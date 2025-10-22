document.addEventListener("DOMContentLoaded", () => {
    const articleWriterPage = document.getElementById("article-writer-tabs");
    if (!articleWriterPage) return;

    // === DOM ELEMENTS ===
    const keywordInput = document.querySelector("#keyword-tab-content input");
    const bulkTextarea = document.querySelector("#bulk-tab-content textarea");
    const urlInput = document.querySelector("#url-tab-content input");
    const analyzeCheckbox = document.getElementById("analyze-competitors-checkbox"); 
    const promptToggle = document.getElementById("prompt-toggle");
    const promptTextarea = document.querySelector("#prompt-section textarea");
    const imageToggle = document.getElementById("image-toggle");
    const imageCountSelect = document.querySelector("#image-section select:first-of-type");
    const imageStyleSelect = document.querySelector("#image-section select:last-of-type");
    const generateButton = document.getElementById("generate-article-btn");
    const articlesTableBody = document.querySelector(".lg\\:col-span-3 table tbody");
    const articleCountHeader = document.querySelector(".lg\\:col-span-3 h2");
    const selectAllCheckbox = document.getElementById("select-all-articles"); // Dùng ID
    const publishSelectedButton = document.getElementById("publish-selected-btn"); 
    const statusFilterSelect = document.getElementById("status-filter"); 

    let articles = []; // Dữ liệu bài viết (sẽ load từ sessionStorage)
    let currentFilter = "all"; 

    // === STORAGE KEY ===
    const STORAGE_KEY = 'smartcontent_articles';

    // === HELPERS ===
    const setButtonLoading = (button, isLoading, text = "Đang xử lý...") => { /* ... (Giữ nguyên) ... */ 
         if (!button) return; if (isLoading) { if (!button.dataset.originalHtml) button.dataset.originalHtml = button.innerHTML; button.disabled = true; button.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i>${text}`; } else { if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml; delete button.dataset.originalHtml; button.disabled = false; }
    };
    const showToast = (message, type = "info") => { /* ... (Giữ nguyên) ... */ 
        const toast = document.createElement("div"); toast.textContent = message; const bg = type === "success" ? "bg-green-600" : type === "error" ? "bg-red-600" : type === "warning" ? "bg-yellow-600" : "bg-gray-700"; toast.className = `fixed bottom-5 right-5 text-white px-5 py-3 rounded-lg shadow-lg transition-all duration-300 ${bg} opacity-0 z-[9999]`; document.body.appendChild(toast); setTimeout(() => (toast.style.opacity = "1"), 50); setTimeout(() => { toast.style.opacity = "0"; setTimeout(() => toast.remove(), 500); }, 3000);
    };
    
    // === SESSION STORAGE FUNCTIONS ===
    const saveArticlesToSession = () => {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(articles));
        } catch (e) {
            console.error("Lỗi lưu vào sessionStorage:", e);
            showToast("⚠️ Không thể lưu trạng thái bài viết vào bộ nhớ tạm.", "warning");
        }
    };
    const loadArticlesFromSession = () => {
        try {
            const storedArticles = sessionStorage.getItem(STORAGE_KEY);
            if (storedArticles) {
                articles = JSON.parse(storedArticles);
                console.log(`Đã tải ${articles.length} bài viết từ sessionStorage.`);
                return true; // Tải thành công
            }
        } catch (e) {
            console.error("Lỗi đọc từ sessionStorage:", e);
            sessionStorage.removeItem(STORAGE_KEY); // Xóa dữ liệu lỗi
        }
        return false; // Không có dữ liệu hoặc lỗi
    };

    // === FETCH ARTICLES (Chỉ gọi nếu sessionStorage trống) ===
    const fetchArticles = async () => {
        // Thử tải từ session trước
        if (loadArticlesFromSession()) {
            renderArticlesTable();
            return; // Đã tải xong
        }
        
        // Nếu session trống, mới gọi API
        console.log("SessionStorage trống, đang gọi API get_articles...");
        try {
            if (typeof authenticatedFetch !== "function") throw new Error("authenticatedFetch missing.");
            // API chỉ trả về bài 'Generated' hoặc 'Error' (không bao gồm 'Draft')
            const result = await authenticatedFetch(`/smartcontent-app/api/article_writer.php?action=get_articles`, { method: 'GET' });
            if (result.success) {
                articles = result.articles || [];
                saveArticlesToSession(); // Lưu vào session sau khi fetch
                renderArticlesTable();
            } else {
                showToast(`❌ Lỗi tải danh sách bài viết từ server: ${result.message}`, "error");
            }
        } catch (error) {
            console.error("Lỗi fetchArticles từ server:", error);
        }
    };

    // === RENDER TABLE ===
    const renderArticlesTable = () => {
        if (!articlesTableBody || !articleCountHeader) return;

        const filteredArticles = articles.filter((a) => {
            if (a.status === 'Generating') return true; 
            if (currentFilter === "all") return true;
            if (currentFilter === "published") return a.status === "Published"; 
            if (currentFilter === "draft") return a.status === "Generated" || a.status === "Draft"; 
            if (currentFilter === "error") return a.status === "Error";
            return true;
        });

        const actualArticleCount = articles.filter(a => a.status !== 'Generating').length;
        articleCountHeader.textContent = `Bài viết đã tạo (${actualArticleCount})`; 
        articlesTableBody.innerHTML = "";

         filteredArticles.sort((a, b) => {
             if (a.status === 'Generating' && b.status !== 'Generating') return -1;
             if (a.status !== 'Generating' && b.status === 'Generating') return 1;
             // Ưu tiên hiển thị ID số (từ DB), sau đó là ID tạm thời (timestamp giảm dần)
             const idA = typeof a.id === 'number' ? a.id : (parseInt(a.id?.split('-')[1] || '0') || 0);
             const idB = typeof b.id === 'number' ? b.id : (parseInt(b.id?.split('-')[1] || '0') || 0);
             return idB - idA; 
         });


        if (filteredArticles.length === 0 && actualArticleCount === 0) { 
            articlesTableBody.innerHTML = `<tr><td colspan="6" class="text-center py-10 text-gray-500">...Chưa có bài viết nào...</td></tr>`;
            return;
        }

        filteredArticles.forEach((article) => {
            const row = document.createElement("tr");
            row.classList.add("border-b", "border-slate-700");
            row.dataset.id = article.id; 
            if(article.status === 'Generating') row.classList.add('opacity-70', 'animate-pulse');

            let statusHtml = "";
            let actionsHtml = "";
            let titleDisplay = article.title || 'N/A';
            let wordCountDisplay = article.word_count || '-';
            let checkboxDisabled = false;
            let errorTooltip = ''; // Tooltip cho trạng thái lỗi

            switch (article.status) {
                case "Generating": 
                    statusHtml = `<span class="px-2 py-1 text-xs font-semibold text-blue-200 bg-blue-500/20 rounded-full flex items-center"><i class="fas fa-spinner fa-spin mr-1"></i>Đang tạo</span>`;
                    actionsHtml = `<span class="text-gray-500 text-xs italic">Đang xử lý...</span>`;
                    titleDisplay = article.title; 
                    checkboxDisabled = true;
                    break;
                case "Generated":
                case "Draft":
                    statusHtml = `<span class="px-2 py-1 text-xs font-semibold text-yellow-200 bg-yellow-500/20 rounded-full">Nháp</span>`;
                    actionsHtml = `
                        <button title="Xem (Chưa làm)" class="view-btn text-gray-400 hover:text-green-400 p-2"><i class="fas fa-eye"></i></button>
                        <button title="Sửa (Chưa làm)" class="edit-btn text-gray-400 hover:text-blue-400 p-2"><i class="fas fa-pencil-alt"></i></button>
                        <button title="Xóa" class="delete-btn text-gray-400 hover:text-red-400 p-2"><i class="fas fa-trash-alt"></i></button>
                    `;
                    break;
                case "Publishing": 
                    statusHtml = `<span class="px-2 py-1 text-xs font-semibold text-blue-200 bg-blue-500/20 rounded-full flex items-center"><i class="fas fa-spinner fa-spin mr-1"></i>Đang đăng</span>`;
                    actionsHtml = `<span class="text-gray-500 text-xs">Đang xử lý...</span>`;
                    checkboxDisabled = true;
                    break;
                case "Published": // Sẽ bị xóa ngay sau khi render thành công
                    statusHtml = `<span class="px-2 py-1 text-xs font-semibold text-green-200 bg-green-500/20 rounded-full">Đã đăng</span>`;
                    actionsHtml = `<button title="Xóa khỏi danh sách" class="delete-btn text-gray-400 hover:text-red-400 p-2"><i class="fas fa-trash-alt"></i></button>`; 
                    checkboxDisabled = true; 
                    break;
                case "Error":
                    // Lỗi bây giờ được lưu trong article.error từ backend
                    errorTooltip = article.error || 'Lỗi không rõ'; 
                    statusHtml = `<span class="px-2 py-1 text-xs font-semibold text-red-300 bg-red-600/30 rounded-full" title="${errorTooltip}">Lỗi</span>`; 
                    actionsHtml = `
                        <button title="Thử lại (Chưa làm)" class="retry-btn text-gray-400 hover:text-blue-400 p-2 opacity-50 cursor-not-allowed"><i class="fas fa-sync-alt"></i></button>
                        <button title="Xóa" class="delete-btn text-gray-400 hover:text-red-400 p-2"><i class="fas fa-trash-alt"></i></button>
                    `;
                    break;
                default:
                    statusHtml = `<span class="text-gray-400">${article.status}</span>`;
            }
            
            const seoScoreHtml = `<div class="w-10 h-10 flex items-center justify-center rounded-full border-2 border-slate-600 text-slate-400 text-xs font-bold bg-slate-700/50" title="Tính năng SEO Score chưa hoàn thiện">-</div>`;

            row.innerHTML = `
                <td class="p-3 w-4"><input type="checkbox" class="form-checkbox h-4 w-4 bg-slate-600 border-slate-500 rounded text-blue-500 article-checkbox" value="${article.id}" ${checkboxDisabled ? 'disabled' : ''}/></td>
                <td class="p-3">${titleDisplay}</td>
                <td class="p-3">${statusHtml}</td>
                <td class="p-3">${wordCountDisplay}</td>
                <td class="p-3">${seoScoreHtml}</td>
                <td class="p-3 whitespace-nowrap">${actionsHtml}</td>
            `;
            articlesTableBody.appendChild(row);
        });
        updatePublishButtonState();
    };

    // === HÀNH ĐỘNG TẠO BÀI VIẾT ===
    generateButton?.addEventListener("click", async () => {
        console.log(">>> Generate button clicked!");
        // ... (Code lấy mode, keywords, url, options giữ nguyên) ...
        const activeTabButton = articleWriterPage.querySelector(".article-writer-tab-button.active");
        const mode = activeTabButton ? activeTabButton.dataset.tab : "keyword";
        let keywords = []; let url = null; let placeholderTitle = "Đang xử lý..."; 
        if (mode === "keyword") { const kw = keywordInput?.value.trim(); if (kw) { keywords.push(kw); placeholderTitle = `Đang tạo bài viết về "${kw}"...`; } } 
        else if (mode === "bulk") { keywords = bulkTextarea?.value.split("\n").map((k) => k.trim()).filter((k) => k) || []; if (keywords.length > 0) { placeholderTitle = `Đang tạo ${keywords.length} bài viết...`; } } 
        else if (mode === "url") { url = urlInput?.value.trim(); if(url) { placeholderTitle = `Đang viết lại từ URL...`; } }
        if (keywords.length === 0 && !url) return showToast("⚠️ Vui lòng nhập Từ khóa hoặc URL.", "warning");
        const analyze = analyzeCheckbox?.checked || false; const useCustomPrompt = promptToggle?.checked || false; const customPrompt = useCustomPrompt ? promptTextarea?.value.trim() : null; 
        if (analyze) { showToast("⚠️ Phân tích đối thủ chưa được hỗ trợ.", "warning"); }

        const payload = { action: "generate_article", mode, keywords, url, analyze: analyze, custom_prompt: customPrompt };

        const tempPlaceholders = []; 
        if (mode === 'keyword' || mode === 'url') {
             const tempId = 'temp-' + Date.now();
             const placeholderArticle = { id: tempId, title: placeholderTitle, status: 'Generating', word_count: '-', seo_score: '-' };
             articles = [placeholderArticle, ...articles]; 
             tempPlaceholders.push(tempId);
        } else if (mode === 'bulk') {
             keywords.forEach(kw => {
                  const tempId = 'temp-' + Date.now() + '-' + kw.replace(/\s+/g, '-').substring(0, 10); // ID tạm duy nhất
                  const placeholderArticle = { id: tempId, title: `Đang tạo bài viết về "${kw}"...`, status: 'Generating', word_count: '-', seo_score: '-' };
                  articles = [placeholderArticle, ...articles]; 
                  tempPlaceholders.push(tempId);
             });
        }
        renderArticlesTable(); // Hiển thị placeholder

        setButtonLoading(generateButton, true, "Đang tạo...");
        try {
            if (typeof authenticatedFetch !== "function") throw new Error("authenticatedFetch missing.");
            const result = await authenticatedFetch(`/smartcontent-app/api/article_writer.php`, { method: "POST", body: JSON.stringify(payload) });
            
            // Xóa placeholder
             articles = articles.filter(a => !tempPlaceholders.includes(a.id)); 

            if (result.success && result.articles) {
                // Thêm kết quả thực tế
                articles = [...result.articles, ...articles]; 
                const successCount = result.articles.filter((a) => !a.error).length;
                const errorCount = result.articles.length - successCount;
                let message = `✅ Đã tạo ${successCount} bài viết.`;
                if (errorCount > 0) message += ` (${errorCount} lỗi)`;
                 // Hiển thị cảnh báo word count nếu có
                 result.articles.forEach(art => {
                     if(art.warning) showToast(`⚠️ ${art.title}: ${art.warning}`, 'warning');
                 });
                showToast(message, errorCount > 0 ? "warning" : "success");
                // Reset input
                if (mode === "keyword") keywordInput.value = "";
                if (mode === "bulk") bulkTextarea.value = "";
                if (mode === "url") urlInput.value = "";
            } else {
                showToast(`❌ Lỗi tạo bài: ${result.message || "Không rõ"}`, "error");
            }
             saveArticlesToSession(); // Lưu trạng thái mới vào session
             renderArticlesTable(); 

        } catch (error) {
            console.error("Lỗi generateArticle:", error);
             articles = articles.filter(a => !tempPlaceholders.includes(a.id)); 
             saveArticlesToSession();
             renderArticlesTable();
        } finally {
            setButtonLoading(generateButton, false);
        }
    });

     // === HÀNH ĐỘNG ĐĂNG BÀI ===
    publishSelectedButton?.addEventListener("click", async () => {
        // ... (Code publishSelectedButton listener giữ nguyên) ...
         const selectedCheckboxes = articlesTableBody.querySelectorAll('.article-checkbox:checked'); const articleIdsToPublish = Array.from(selectedCheckboxes).map(cb => cb.value); if (articleIdsToPublish.length === 0) return showToast("⚠️ Vui lòng chọn ít nhất một bài viết (Nháp) để đăng.", "warning"); if (!confirm(`Bạn có chắc muốn đăng ${articleIdsToPublish.length} bài viết đã chọn lên WordPress?`)) return; setButtonLoading(publishSelectedButton, true, `Đang đăng ${articleIdsToPublish.length} bài...`); articleIdsToPublish.forEach(id => updateArticleStatusLocally(id, 'Publishing')); saveArticlesToSession(); renderArticlesTable(); try { if (typeof authenticatedFetch !== "function") throw new Error("authenticatedFetch missing."); const result = await authenticatedFetch(`/smartcontent-app/api/article_writer.php`, { method: 'POST', body: JSON.stringify({ action: "publish_articles", article_ids: articleIdsToPublish }) }); let successCount = 0; let errorCount = 0; if (result.success && result.results) { result.results.forEach(res => { if (res.success) { successCount++; articles = articles.filter(a => a.id != res.id); } else { errorCount++; updateArticleStatusLocally(res.id, 'Error', res.error || 'Lỗi đăng bài không rõ.'); } }); let message = `✅ Đăng thành công ${successCount} bài viết.`; if(errorCount > 0) message += ` (${errorCount} lỗi)`; showToast(message, errorCount > 0 ? "warning" : "success"); } else { showToast(`❌ Lỗi khi thực hiện đăng bài: ${result.message || 'Không rõ'}`, "error"); articleIdsToPublish.forEach(id => updateArticleStatusLocally(id, 'Generated')); } saveArticlesToSession(); renderArticlesTable(); } catch (error) { console.error("Lỗi publishArticles:", error); articleIdsToPublish.forEach(id => updateArticleStatusLocally(id, 'Generated')); saveArticlesToSession(); renderArticlesTable(); } finally { setButtonLoading(publishSelectedButton, false); }
    });

     // === HÀNH ĐỘNG XÓA BÀI (VÀ CLICK KHÁC TRONG BẢNG) ===
    articlesTableBody?.addEventListener('click', async (e) => {
        const target = e.target as HTMLElement; // Ép kiểu để dùng closest
        
        // --- XỬ LÝ NÚT XÓA ---
        const deleteBtn = target.closest('.delete-btn');
        if (deleteBtn) {
            const row = deleteBtn.closest('tr');
            const articleId = row?.dataset?.id;
            if (!articleId) return;
            console.log("Delete button clicked for ID:", articleId); // Debug

            const article = articles.find((a) => a.id == articleId); // So sánh == vì ID có thể là số hoặc chuỗi 'temp-'
            const confirmMsg = (article && article.status !== 'Published') 
                             ? "Bạn có chắc muốn xóa bài viết nháp này?" 
                             : "Xóa khỏi danh sách?"; 

            if (!confirm(confirmMsg)) return;

            setButtonLoading(deleteBtn, true); 
            try {
                if (typeof authenticatedFetch !== "function") throw new Error("authenticatedFetch missing.");
                
                // Chỉ gọi API xóa nếu ID là số (đã lưu DB)
                let apiSuccess = true;
                if (typeof article?.id === 'number') {
                     const result = await authenticatedFetch(`/smartcontent-app/api/article_writer.php`, { 
                        method: 'POST', 
                        body: JSON.stringify({ action: "delete_article", id: articleId }) 
                    });
                    apiSuccess = result.success;
                     if (!apiSuccess) {
                          showToast(`❌ Lỗi khi xóa bài trên server: ${result.message || 'Không rõ'}`, "error");
                     }
                }
                
                // Nếu API thành công (hoặc là bài tạm chưa lưu DB), thì xóa khỏi JS
                if(apiSuccess){
                     articles = articles.filter((a) => a.id != articleId); // So sánh != 
                     saveArticlesToSession();
                     renderArticlesTable();
                     showToast("🗑️ Đã xóa bài viết.", "success");
                }
            } catch(error) {
                 console.error("Lỗi deleteArticle:", error);
                 showToast("❌ Lỗi không xác định khi xóa.", "error"); // Hiển thị lỗi chung
            } finally {
                 setButtonLoading(deleteBtn, false); 
            }
            return; // Dừng xử lý sau khi xóa
        } // Kết thúc xử lý nút xóa

        // --- XỬ LÝ NÚT XEM (Tạm thời log) ---
        const viewBtn = target.closest('.view-btn');
        if (viewBtn && !viewBtn.classList.contains('opacity-50')) {
             const row = viewBtn.closest('tr');
             const articleId = row?.dataset?.id;
             console.log("View button clicked for ID:", articleId);
             showToast("Tính năng xem chi tiết chưa được triển khai.", "info");
             // Mở modal hoặc xem trước tại đây
             return;
        }

         // --- XỬ LÝ NÚT SỬA (Tạm thời log) ---
        const editBtn = target.closest('.edit-btn');
        if (editBtn && !editBtn.classList.contains('opacity-50')) {
             const row = editBtn.closest('tr');
             const articleId = row?.dataset?.id;
             console.log("Edit button clicked for ID:", articleId);
             showToast("Tính năng sửa bài viết chưa được triển khai.", "info");
              // Mở modal hoặc editor tại đây
             return;
        }

    }); // Kết thúc listener của articlesTableBody

    // === XỬ LÝ CHECKBOX ALL ===
    selectAllCheckbox?.addEventListener('change', (e) => { /* ... (Giữ nguyên) ... */ 
        const isChecked = (e.target as HTMLInputElement).checked; articlesTableBody.querySelectorAll('.article-checkbox:not(:disabled)').forEach(checkbox => { (checkbox as HTMLInputElement).checked = isChecked; }); updatePublishButtonState(); 
    });

    // === CẬP NHẬT TRẠNG THÁI NÚT PUBLISH KHI CHECKBOX THAY ĐỔI ===
     articlesTableBody?.addEventListener('change', (e) => { /* ... (Giữ nguyên) ... */ 
         if ((e.target as HTMLElement).classList.contains('article-checkbox')) { updatePublishButtonState(); } 
     });

    // === HÀM CẬP NHẬT TRẠNG THÁI NÚT PUBLISH ===
    const updatePublishButtonState = () => { /* ... (Giữ nguyên) ... */ 
         const selectedCheckboxes = articlesTableBody.querySelectorAll('.article-checkbox:checked:not(:disabled)'); const selectedCount = selectedCheckboxes.length; if (publishSelectedButton) { publishSelectedButton.disabled = selectedCount === 0; publishSelectedButton.textContent = selectedCount > 0 ? `Đăng ${selectedCount} bài đã chọn` : 'Đăng bài đã chọn'; publishSelectedButton.classList.toggle('opacity-50', selectedCount === 0); publishSelectedButton.classList.toggle('cursor-not-allowed', selectedCount === 0); } const totalCheckboxes = articlesTableBody.querySelectorAll('.article-checkbox:not(:disabled)').length; if (selectAllCheckbox) { (selectAllCheckbox as HTMLInputElement).checked = totalCheckboxes > 0 && selectedCount === totalCheckboxes; (selectAllCheckbox as HTMLInputElement).indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes; }
    };
    
    // === HÀM CẬP NHẬT TRẠNG THÁI LOCAL ===
    const updateArticleStatusLocally = (id, newStatus, errorMsg = null) => { /* ... (Giữ nguyên) ... */ 
         const index = articles.findIndex(a => a.id == id); if (index !== -1) { articles[index].status = newStatus; if (newStatus === 'Error') { articles[index].error = errorMsg; } } // Không lưu vào content nữa
    };
    
     // === LỌC TRẠNG THÁI ===
     statusFilterSelect?.addEventListener('change', (e) => { /* ... (Giữ nguyên) ... */ 
         currentFilter = (e.target as HTMLSelectElement).value; renderArticlesTable(); 
     });

    // === CẢNH BÁO BEFOREUNLOAD ===
    window.addEventListener('beforeunload', (event) => {
        const hasUnpublished = articles.some(a => a.status === 'Generated' || a.status === 'Draft' || a.status === 'Generating'); // Thêm Generating
        if (hasUnpublished) {
            const confirmationMessage = 'Bạn có bài viết chưa được đăng. Rời khỏi trang này sẽ làm mất các bài viết đó. Bạn có chắc muốn rời đi?';
            event.preventDefault(); 
            event.returnValue = confirmationMessage; // Standard for most browsers
            return confirmationMessage; // For older browsers
        }
    });

    // === INIT LOAD ===
    if (typeof authenticatedFetch === "function") {
        fetchArticles(); 
    } else {
        setTimeout(fetchArticles, 300); 
    }

}); // End DOMContentLoaded