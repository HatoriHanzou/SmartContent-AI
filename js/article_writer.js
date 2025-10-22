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
    console.log(">>> Script running BEFORE getting generateButton"); // Debug Log 1
    const generateButton = document.getElementById("generate-article-btn");
    const articlesTableBody = document.querySelector(".lg\\:col-span-3 table tbody");
    const articleCountHeader = document.querySelector(".lg\\:col-span-3 h2");
    const selectAllCheckbox = document.getElementById("select-all-articles");
    const publishSelectedButton = document.getElementById("publish-selected-btn");
    const statusFilterSelect = document.getElementById("status-filter");

    let articles = []; // Dữ liệu bài viết (sẽ load từ sessionStorage)
    let currentFilter = "all";

    // === STORAGE KEY ===
    const STORAGE_KEY = "smartcontent_articles";

    // === HELPERS ===
    const setButtonLoading = (button, isLoading, text = "Đang xử lý...") => {
        if (!button) return;
        if (isLoading) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }
            button.disabled = true;
            button.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i>${text}`;
        } else {
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
            button.disabled = false;
        }
    };
    const showToast = (message, type = "info") => {
        const toast = document.createElement("div");
        toast.textContent = message;
        const bg =
            type === "success"
                ? "bg-green-600"
                : type === "error"
                ? "bg-red-600"
                : type === "warning"
                ? "bg-yellow-600"
                : "bg-gray-700";
        toast.className = `fixed bottom-5 right-5 text-white px-5 py-3 rounded-lg shadow-lg transition-all duration-300 ${bg} opacity-0 z-[9999]`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = "1";
        }, 50);
        setTimeout(() => {
            toast.style.opacity = "0";
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 500);
        }, 3000);
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
                return true;
            }
        } catch (e) {
            console.error("Lỗi đọc từ sessionStorage:", e);
            sessionStorage.removeItem(STORAGE_KEY);
        }
        return false;
    };

    // === FETCH ARTICLES (Chỉ gọi nếu sessionStorage trống) ===
    const fetchArticles = async () => {
        if (loadArticlesFromSession()) {
            renderArticlesTable();
            return;
        }

        console.log("SessionStorage trống, đang gọi API get_articles...");
        try {
            if (typeof authenticatedFetch !== "function") throw new Error("authenticatedFetch missing.");
            const result = await authenticatedFetch(`/smartcontent-app/api/article_writer.php?action=get_articles`, {
                method: "GET",
            });
            if (result.success) {
                articles = result.articles || [];
                articles.forEach((a) => {
                    if (a.content_excerpt && !a.content) a.content = a.content_excerpt;
                });
                saveArticlesToSession();
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
            if (a.status === "Generating") return true;
            if (currentFilter === "all") return true;
            if (currentFilter === "published") return a.status === "Published";
            if (currentFilter === "draft") return a.status === "Generated" || a.status === "Draft";
            if (currentFilter === "error") return a.status === "Error";
            return true;
        });

        const actualArticleCount = articles.filter((a) => a.status !== "Generating").length;
        articleCountHeader.textContent = `Bài viết đã tạo (${actualArticleCount})`;
        articlesTableBody.innerHTML = "";

        filteredArticles.sort((a, b) => {
            if (a.status === "Generating" && b.status !== "Generating") return -1;
            if (a.status !== "Generating" && b.status === "Generating") return 1;
            const idA = typeof a.id === "number" ? a.id : parseInt(a.id?.split("-")[1] || "0") || 0;
            const idB = typeof b.id === "number" ? b.id : parseInt(b.id?.split("-")[1] || "0") || 0;
            return idB - idA;
        });

        if (filteredArticles.length === 0 && actualArticleCount === 0) {
            articlesTableBody.innerHTML = `<tr><td colspan="6" class="text-center py-10 text-gray-500">...Chưa có bài viết nào...</td></tr>`;
            return;
        }

        filteredArticles.forEach((article) => {
            const row = document.createElement("tr");
            row.classList.add("border-b", "border-slate-700");
            row.dataset.id = String(article.id); // Chuyển ID sang string
            if (article.status === "Generating") row.classList.add("opacity-70", "animate-pulse");

            let statusHtml = "";
            let actionsHtml = "";
            let titleDisplay = article.title || "N/A";
            let wordCountDisplay = article.word_count || "-";
            let checkboxDisabled = false;
            let errorTooltip = "";

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
                case "Published":
                    statusHtml = `<span class="px-2 py-1 text-xs font-semibold text-green-200 bg-green-500/20 rounded-full">Đã đăng</span>`;
                    actionsHtml = `<button title="Xóa khỏi danh sách" class="delete-btn text-gray-400 hover:text-red-400 p-2"><i class="fas fa-trash-alt"></i></button>`;
                    checkboxDisabled = true;
                    break;
                case "Error":
                    errorTooltip = article.error || article.content || "Lỗi không rõ";
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
                <td class="p-3 w-4"><input type="checkbox" class="form-checkbox h-4 w-4 bg-slate-600 border-slate-500 rounded text-blue-500 article-checkbox" value="${
                    article.id
                }" ${checkboxDisabled ? "disabled" : ""}/></td>
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

    console.log(">>> Script running BEFORE adding listener"); // Debug Log 2
    // === HÀNH ĐỘNG TẠO BÀI VIẾT ===
    generateButton?.addEventListener("click", async () => {
        console.log(">>> Generate button clicked!"); // Debug Log 3

        const activeTabButton = articleWriterPage.querySelector(".article-writer-tab-button.active");
        const mode = activeTabButton ? activeTabButton.dataset.tab : "keyword";
        let keywords = [];
        let url = null;
        let placeholderTitle = "Đang xử lý...";
        if (mode === "keyword") {
            const kw = keywordInput?.value.trim();
            if (kw) {
                keywords.push(kw);
                placeholderTitle = `Đang tạo bài viết về "${kw}"...`;
            }
        } else if (mode === "bulk") {
            keywords =
                bulkTextarea?.value
                    .split("\n")
                    .map((k) => k.trim())
                    .filter((k) => k) || [];
            if (keywords.length > 0) {
                placeholderTitle = `Đang tạo ${keywords.length} bài viết...`;
            }
        } else if (mode === "url") {
            url = urlInput?.value.trim();
            if (url) {
                placeholderTitle = `Đang viết lại từ URL...`;
            }
        }
        if (keywords.length === 0 && !url) return showToast("⚠️ Vui lòng nhập Từ khóa hoặc URL.", "warning");
        const analyze = analyzeCheckbox?.checked || false;
        const useCustomPrompt = promptToggle?.checked || false;
        const customPrompt = useCustomPrompt ? promptTextarea?.value.trim() : null;
        if (analyze) {
            showToast("⚠️ Phân tích đối thủ chưa được hỗ trợ.", "warning");
        }

        const payload = {
            action: "generate_article",
            mode,
            keywords,
            url,
            analyze: analyze,
            custom_prompt: customPrompt,
        };

        const tempPlaceholders = [];
        if (mode === "keyword" || mode === "url") {
            const tempId = "temp-" + Date.now();
            const placeholderArticle = {
                id: tempId,
                title: placeholderTitle,
                status: "Generating",
                word_count: "-",
                seo_score: "-",
            };
            articles = [placeholderArticle, ...articles];
            tempPlaceholders.push(tempId);
        } else if (mode === "bulk") {
            keywords.forEach((kw) => {
                const tempId = "temp-" + Date.now() + "-" + kw.replace(/\s+/g, "-").substring(0, 10);
                const placeholderArticle = {
                    id: tempId,
                    title: `Đang tạo bài viết về "${kw}"...`,
                    status: "Generating",
                    word_count: "-",
                    seo_score: "-",
                };
                articles = [placeholderArticle, ...articles];
                tempPlaceholders.push(tempId);
            });
        }
        renderArticlesTable();

        setButtonLoading(generateButton, true, "Đang tạo...");
        try {
            if (typeof authenticatedFetch !== "function") throw new Error("authenticatedFetch missing.");
            const result = await authenticatedFetch(`/smartcontent-app/api/article_writer.php`, {
                method: "POST",
                body: JSON.stringify(payload),
            });

            articles = articles.filter((a) => !tempPlaceholders.includes(String(a.id))); // Chuyển ID sang string để so sánh

            if (result.success && result.articles) {
                const newArticlesWithRealIds = result.articles.map((newArt, index) => ({
                    ...newArt,
                    id: newArt.id ?? tempPlaceholders[index] ?? `error-${Date.now()}-${index}`,
                }));

                articles = [...newArticlesWithRealIds, ...articles];
                const successCount = newArticlesWithRealIds.filter((a) => !a.error).length;
                const errorCount = newArticlesWithRealIds.length - successCount;
                let message = `✅ Đã tạo ${successCount} bài viết.`;
                if (errorCount > 0) message += ` (${errorCount} lỗi)`;
                newArticlesWithRealIds.forEach((art) => {
                    if (art.warning) showToast(`⚠️ ${art.title}: ${art.warning}`, "warning");
                });
                showToast(message, errorCount > 0 ? "warning" : "success");

                if (mode === "keyword") keywordInput.value = "";
                if (mode === "bulk") bulkTextarea.value = "";
                if (mode === "url") urlInput.value = "";
            } else {
                showToast(`❌ Lỗi tạo bài: ${result.message || "Không rõ"}`, "error");
                tempPlaceholders.forEach((tempId) => {
                    updateArticleStatusLocally(tempId, "Error", result.message || "Lỗi không xác định");
                });
            }
            saveArticlesToSession();
            renderArticlesTable();
        } catch (error) {
            console.error("Lỗi generateArticle:", error);
            articles = articles.filter((a) => !tempPlaceholders.includes(String(a.id)));
            tempPlaceholders.forEach((tempId) => {
                const errorArticle = {
                    id: tempId,
                    title: `Lỗi tạo bài (ID tạm: ${tempId})`,
                    status: "Error",
                    error: "Lỗi mạng hoặc kết nối server.",
                    word_count: "-",
                    seo_score: "-",
                };
                articles = [errorArticle, ...articles];
            });
            saveArticlesToSession();
            renderArticlesTable();
        } finally {
            setButtonLoading(generateButton, false);
        }
    });

    // === HÀNH ĐỘNG ĐĂNG BÀI ===
    publishSelectedButton?.addEventListener("click", async () => {
        const selectedCheckboxes = articlesTableBody.querySelectorAll(".article-checkbox:checked");
        const articleIdsToPublish = Array.from(selectedCheckboxes).map((cb) => cb.value);
        if (articleIdsToPublish.length === 0)
            return showToast("⚠️ Vui lòng chọn ít nhất một bài viết (Nháp) để đăng.", "warning");
        if (!confirm(`Bạn có chắc muốn đăng ${articleIdsToPublish.length} bài viết đã chọn lên WordPress?`)) return;
        setButtonLoading(publishSelectedButton, true, `Đang đăng ${articleIdsToPublish.length} bài...`);
        articleIdsToPublish.forEach((id) => updateArticleStatusLocally(id, "Publishing"));
        saveArticlesToSession();
        renderArticlesTable();
        try {
            if (typeof authenticatedFetch !== "function") throw new Error("authenticatedFetch missing.");
            const result = await authenticatedFetch(`/smartcontent-app/api/article_writer.php`, {
                method: "POST",
                body: JSON.stringify({ action: "publish_articles", article_ids: articleIdsToPublish }),
            });
            let successCount = 0;
            let errorCount = 0;
            if (result.success && result.results) {
                result.results.forEach((res) => {
                    if (res.success) {
                        successCount++;
                        articles = articles.filter((a) => String(a.id) != String(res.id)); // So sánh string
                    } else {
                        errorCount++;
                        updateArticleStatusLocally(res.id, "Error", res.error || "Lỗi đăng bài không rõ.");
                    }
                });
                let message = `✅ Đăng thành công ${successCount} bài viết.`;
                if (errorCount > 0) message += ` (${errorCount} lỗi)`;
                showToast(message, errorCount > 0 ? "warning" : "success");
            } else {
                showToast(`❌ Lỗi khi thực hiện đăng bài: ${result.message || "Không rõ"}`, "error");
                articleIdsToPublish.forEach((id) => updateArticleStatusLocally(id, "Generated"));
            }
            saveArticlesToSession();
            renderArticlesTable();
        } catch (error) {
            console.error("Lỗi publishArticles:", error);
            articleIdsToPublish.forEach((id) => updateArticleStatusLocally(id, "Generated"));
            saveArticlesToSession();
            renderArticlesTable();
        } finally {
            setButtonLoading(publishSelectedButton, false);
        }
    });

    // === HÀNH ĐỘNG XÓA BÀI (VÀ CLICK KHÁC TRONG BẢNG) ===
    articlesTableBody?.addEventListener("click", async (e) => {
        // --- SỬA LỖI: Xóa 'as HTMLElement' ---
        const target = e.target; // Không cần ép kiểu
        // --- KẾT THÚC SỬA LỖI ---

        // Kiểm tra xem target có phải là HTMLElement không (đề phòng click vào khoảng trống)
        if (!(target instanceof HTMLElement)) return;

        // --- XỬ LÝ NÚT XÓA ---
        const deleteBtn = target.closest(".delete-btn");
        if (deleteBtn) {
            const row = deleteBtn.closest("tr");
            const articleId = row?.dataset?.id; // ID là string từ dataset
            if (!articleId) return;
            console.log("Delete button clicked for ID:", articleId);

            const article = articles.find((a) => String(a.id) == articleId); // So sánh string
            const confirmMsg =
                article && article.status !== "Published"
                    ? "Bạn có chắc muốn xóa bài viết nháp này?"
                    : "Xóa khỏi danh sách?";

            if (!confirm(confirmMsg)) return;

            setButtonLoading(deleteBtn, true);
            try {
                if (typeof authenticatedFetch !== "function") throw new Error("authenticatedFetch missing.");

                let apiSuccess = true;
                const numericId = parseInt(articleId);
                if (!isNaN(numericId) && articleId.indexOf("temp-") === -1) {
                    const result = await authenticatedFetch(`/smartcontent-app/api/article_writer.php`, {
                        method: "POST",
                        body: JSON.stringify({ action: "delete_article", id: numericId }),
                    });
                    apiSuccess = result.success;
                    if (!apiSuccess) {
                        showToast(`❌ Lỗi khi xóa bài trên server: ${result.message || "Không rõ"}`, "error");
                    }
                } else {
                    console.log("Deleting temporary article locally:", articleId);
                }

                if (apiSuccess) {
                    articles = articles.filter((a) => String(a.id) != articleId); // So sánh string để xóa khỏi mảng
                    saveArticlesToSession();
                    renderArticlesTable();
                    showToast("🗑️ Đã xóa bài viết.", "success");
                }
            } catch (error) {
                console.error("Lỗi deleteArticle:", error);
                showToast("❌ Lỗi không xác định khi xóa.", "error");
            } finally {
                setButtonLoading(deleteBtn, false);
            }
            return;
        }

        // --- XỬ LÝ NÚT XEM ---
        const viewBtn = target.closest(".view-btn");
        if (viewBtn && !viewBtn.classList.contains("opacity-50")) {
            const row = viewBtn.closest("tr");
            const articleId = row?.dataset?.id;
            console.log("View button clicked for ID:", articleId);
            showToast("Tính năng xem chi tiết chưa được triển khai.", "info");
            return;
        }

        // --- XỬ LÝ NÚT SỬA ---
        const editBtn = target.closest(".edit-btn");
        if (editBtn && !editBtn.classList.contains("opacity-50")) {
            const row = editBtn.closest("tr");
            const articleId = row?.dataset?.id;
            console.log("Edit button clicked for ID:", articleId);
            showToast("Tính năng sửa bài viết chưa được triển khai.", "info");
            return;
        }
    }); // Kết thúc listener của articlesTableBody

    // === XỬ LÝ CHECKBOX ALL ===
    selectAllCheckbox?.addEventListener("change", (e) => {
        const isChecked = e.target.checked;
        articlesTableBody.querySelectorAll(".article-checkbox:not(:disabled)").forEach((checkbox) => {
            checkbox.checked = isChecked;
        });
        // Cập nhật lại trạng thái (text và disabled) của nút "Đăng bài đã chọn"
        updatePublishButtonState();
    });

    // === CẬP NHẬT TRẠNG THÁI NÚT PUBLISH KHI CHECKBOX THAY ĐỔI ===
    articlesTableBody?.addEventListener("change", (e) => {
        if (e.target && e.target.classList.contains("article-checkbox")) {
            updatePublishButtonState();
        }
    });

    // === HÀM CẬP NHẬT TRẠNG THÁI NÚT PUBLISH ===
    const updatePublishButtonState = () => {
        const selectedCheckboxes = articlesTableBody.querySelectorAll(".article-checkbox:checked:not(:disabled)");
        const selectedCount = selectedCheckboxes.length;
        if (publishSelectedButton) {
            publishSelectedButton.disabled = selectedCount === 0;
            publishSelectedButton.textContent =
                selectedCount > 0 ? `Đăng ${selectedCount} bài đã chọn` : "Đăng bài đã chọn";
            publishSelectedButton.classList.toggle("opacity-50", selectedCount === 0);
            publishSelectedButton.classList.toggle("cursor-not-allowed", selectedCount === 0);
        }
        const totalCheckboxes = articlesTableBody.querySelectorAll(".article-checkbox:not(:disabled)").length;
        const selectAllInputElement = selectAllCheckbox; // Không cần ép kiểu
        if (selectAllInputElement) {
            selectAllInputElement.checked = totalCheckboxes > 0 && selectedCount === totalCheckboxes;
            selectAllInputElement.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
        }
    };

    // === HÀM CẬP NHẬT TRẠNG THÁI LOCAL ===
    const updateArticleStatusLocally = (id, newStatus, errorMsg = null) => {
        const index = articles.findIndex((a) => String(a.id) == String(id)); // So sánh string
        if (index !== -1) {
            articles[index].status = newStatus;
            if (newStatus === "Error") {
                articles[index].error = errorMsg;
            }
        }
    };

    // === LỌC TRẠNG THÁI ===
    statusFilterSelect?.addEventListener("change", (e) => {
        currentFilter = e.target.value; // Sửa lỗi TypeScript
        renderArticlesTable();
    });

    // === CẢNH BÁO BEFOREUNLOAD ===
    window.addEventListener("beforeunload", (event) => {
        const hasUnpublished = articles.some(
            (a) => a.status === "Generated" || a.status === "Draft" || a.status === "Generating"
        );
        if (hasUnpublished) {
            const confirmationMessage =
                "Bạn có bài viết chưa được đăng hoặc đang tạo. Rời khỏi trang này sẽ làm mất các bài viết đó. Bạn có chắc muốn rời đi?";
            event.preventDefault();
            event.returnValue = confirmationMessage;
            return confirmationMessage;
        }
    });

    // === INIT LOAD ===
    if (typeof authenticatedFetch === "function") {
        fetchArticles();
    } else {
        setTimeout(fetchArticles, 300);
    }
}); // End DOMContentLoaded
