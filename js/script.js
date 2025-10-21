document.addEventListener("DOMContentLoaded", () => {
    // --- Article Writer Page Logic (GIỮ NGUYÊN) ---
    const articleWriterTabsContainer = document.getElementById("article-writer-tabs");
    if (articleWriterTabsContainer) {
        const tabButtons = articleWriterTabsContainer.querySelectorAll(".article-writer-tab-button");
        const tabPanes = document.querySelectorAll(".article-writer-tab-pane");
        const underline = articleWriterTabsContainer.querySelector(".tab-underline");

        const moveUnderline = (target) => {
            if (underline && target) {
                const containerRect = articleWriterTabsContainer.getBoundingClientRect();
                const targetRect = target.getBoundingClientRect();
                underline.style.left = `${targetRect.left - containerRect.left}px`;
                underline.style.width = `${targetRect.width}px`;
            }
        };

        const initialActiveTab = articleWriterTabsContainer.querySelector(".active");
        if (initialActiveTab) {
            setTimeout(() => moveUnderline(initialActiveTab), 100);
        }

        tabButtons.forEach((button) => {
            button.addEventListener("click", (e) => {
                const tabId = e.currentTarget.dataset.tab;

                tabButtons.forEach((btn) => btn.classList.remove("active", "text-blue-500"));
                e.currentTarget.classList.add("active", "text-blue-500");

                tabPanes.forEach((pane) => {
                    if (pane.id === `${tabId}-tab-content`) {
                        pane.classList.remove("hidden");
                    } else {
                        pane.classList.add("hidden");
                    }
                });

                moveUnderline(e.currentTarget);
            });
        });
    }

    const promptToggle = document.getElementById("prompt-toggle");
    const promptSection = document.getElementById("prompt-section");
    if (promptToggle && promptSection) {
        promptToggle.addEventListener("change", () => {
            promptSection.style.display = promptToggle.checked ? "block" : "none";
        });
    }

    const imageToggle = document.getElementById("image-toggle");
    const imageSection = document.getElementById("image-section");
    if (imageToggle && imageSection) {
        imageToggle.addEventListener("change", () => {
            imageSection.style.display = imageToggle.checked ? "grid" : "none";
        });
    }

    // --- Generic Tab Handler for Admin and Settings (GIỮ NGUYÊN) ---
    // Hàm này được dùng chung cho cả admin.html và settings.html
    const handleGenericTabSwitching = (tabsContainerId, tabButtonClass, tabPaneClass) => {
        const tabsContainer = document.getElementById(tabsContainerId);
        if (!tabsContainer) return;

        const tabButtons = tabsContainer.querySelectorAll(`.${tabButtonClass}`);
        // Sửa lại: Tìm 'main' từ gốc, hoặc body
        const contentContainer = document.querySelector("main") || document.body;
        const tabPanes = contentContainer.querySelectorAll(`.${tabPaneClass}`);

        tabButtons.forEach((button) => {
            button.addEventListener("click", () => {
                const tab = button.dataset.tab;

                tabButtons.forEach((btn) => {
                    btn.classList.remove("text-white", "bg-slate-700");
                    btn.classList.add("text-gray-300", "hover:text-white", "hover:bg-slate-700/50");
                });
                button.classList.add("text-white", "bg-slate-700");
                button.classList.remove("text-gray-300");

                tabPanes.forEach((pane) => {
                    if (pane.id === `${tab}-tab-content`) {
                        pane.classList.remove("hidden");
                    } else {
                        pane.classList.add("hidden");
                    }
                });
            });
        });
    };

    handleGenericTabSwitching("admin-tabs", "admin-tab-button", "admin-tab-pane");
    handleGenericTabSwitching("settings-tabs", "settings-tab-button", "settings-tab-pane");

    // --- (ĐÃ XÓA) Logic trang Admin đã được chuyển sang js/admin.js ---
});
