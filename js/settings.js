document.addEventListener("DOMContentLoaded", () => {
    // --- DOM Elements ---
    const profileForm = document.getElementById("profile-form");
    const passwordForm = document.getElementById("password-form");
    const aiSettingsForm = document.getElementById("ai-settings-form");
    const emailInput = document.getElementById("profile-email");
    const profileNameInput = document.getElementById("profile-name");
    const profilePhoneInput = document.getElementById("profile-phone");
    const profileStatusEl = document.getElementById("profile-status");
    const licenseHistoryEl = document.getElementById("license-history");
    const currentPasswordInput = document.getElementById("current-password");
    const newPasswordInput = document.getElementById("new-password");
    const confirmPasswordInput = document.getElementById("confirm-password");

    // Lấy thông tin user hiện tại (chỉ dùng để check quyền admin cho ô email)
    const userString = localStorage.getItem("currentUser");
    const currentUserForCheck = userString ? JSON.parse(userString) : null;

    // --- Functions ---
    const fetchSettings = async () => {
        // Đặt trạng thái loading ban đầu
        if (profileStatusEl) profileStatusEl.innerHTML = `<span class="text-gray-400">Đang tải...</span>`;
        if (licenseHistoryEl) licenseHistoryEl.innerHTML = `<p class="text-gray-400">Đang tải...</p>`;

        try {
            // Kiểm tra authenticatedFetch tồn tại (từ layout.js)
            if (typeof authenticatedFetch !== "function") {
                console.error("settings.js: authenticatedFetch is not defined. Make sure layout.js loads first.");
                throw new Error("Lỗi tải cấu hình ứng dụng.");
            }
            const data = await authenticatedFetch(`/smartcontent-app/api/settings.php?action=get_settings`, {
                method: "GET",
            });

            populateProfileData(data.profile);
            populateLicenseData(data.license);
        } catch (error) {
            // Lỗi đã được xử lý (alert và/hoặc logout) trong authenticatedFetch
            console.error("settings.js: Lỗi cuối cùng khi tải cài đặt:", error);
            if (profileStatusEl) profileStatusEl.innerHTML = `<span class="text-red-400">Lỗi tải dữ liệu</span>`;
            if (licenseHistoryEl) licenseHistoryEl.innerHTML = `<p class="text-red-400">Lỗi tải Lịch sử License.</p>`;
        }
    };

    const populateProfileData = (profile) => {
        if (!profile) {
            console.warn("populateProfileData: Profile data is null.");
            return;
        }
        if (profileNameInput) profileNameInput.value = profile.name || "";
        if (emailInput) emailInput.value = profile.email || "";
        if (profilePhoneInput) profilePhoneInput.value = profile.phone || "";

        // Mở khóa email cho Admin
        if (currentUserForCheck && currentUserForCheck.role && currentUserForCheck.role.toLowerCase() === "admin") {
            if (emailInput) {
                emailInput.readOnly = false;
                emailInput.classList.remove("bg-slate-900", "text-gray-400", "cursor-not-allowed");
                emailInput.classList.add("bg-slate-700");
            }
        } else {
            if (emailInput) {
                emailInput.readOnly = true;
                emailInput.classList.add("bg-slate-900", "text-gray-400", "cursor-not-allowed");
                emailInput.classList.remove("bg-slate-700");
            }
        }
    };

    const populateLicenseData = (license) => {
        if (!profileStatusEl || !licenseHistoryEl) {
            console.error("populateLicenseData: Status or History element not found.");
            return;
        }

        if (license && license.status === "Active") {
            let expiryText = "Vĩnh viễn";
            if (license.expires_at) {
                try {
                    const expiryDate = new Date(license.expires_at);
                    if (!isNaN(expiryDate.getTime())) {
                        expiryText = `Đến ngày ${expiryDate.toLocaleDateString("vi-VN")}`;
                    } else {
                        console.warn("Ngày hết hạn không hợp lệ:", license.expires_at);
                        expiryText = "Không xác định";
                    }
                } catch (e) {
                    console.error("Lỗi xử lý ngày hết hạn:", e);
                    expiryText = "Lỗi định dạng";
                }
            }
            const planName = license.plan_name || "Gói Quản trị viên"; // Fallback for admin or missing plan
            profileStatusEl.innerHTML = `<span class="px-2 py-1 text-xs font-semibold text-green-200 bg-green-500/20 rounded-full">Đã kích hoạt</span>`;
            licenseHistoryEl.innerHTML = `
                <div class="text-gray-300">
                    <p class="mb-2"><span class="font-semibold text-gray-400">Gói cước:</span> ${planName}</p>
                    <p class="mb-2"><span class="font-semibold text-gray-400">License Key:</span> <span class="font-mono">${
                        license.license_key || "N/A"
                    }</span></p>
                    <p><span class="font-semibold text-gray-400">Hiệu lực:</span> ${expiryText}</p>
                </div>`;
        } else {
            profileStatusEl.innerHTML = `<span class="px-2 py-1 text-xs font-semibold text-yellow-200 bg-yellow-500/20 rounded-full">Chưa kích hoạt</span>`;
            licenseHistoryEl.innerHTML = `<p class="text-gray-400">Bạn chưa kích hoạt license nào.</p>`;
        }
    };

    // Hàm cập nhật profile (dùng authenticatedFetch)
    const handleUpdateProfile = async (payload) => {
        try {
            // Kiểm tra authenticatedFetch tồn tại
            if (typeof authenticatedFetch !== "function") throw new Error("Lỗi cấu hình (authenticatedFetch missing).");

            const result = await authenticatedFetch(`/smartcontent-app/api/settings.php`, {
                method: "POST",
                body: JSON.stringify({ action: "update_profile", ...payload }),
            });

            alert(result.message); // Thông báo thành công

            // Cập nhật localStorage nếu admin tự đổi email/tên
            if (result.success && result.needsUpdate) {
                const userFromLS = JSON.parse(localStorage.getItem("currentUser") || "{}"); // Thêm fallback {}
                const updatedUser = { ...userFromLS, name: result.newData.name, email: result.newData.email };
                localStorage.setItem("currentUser", JSON.stringify(updatedUser));

                // Cập nhật header ngay lập tức bằng cách gọi lại initializeLayout từ layout.js
                if (typeof initializeLayout === "function") {
                    console.log("handleUpdateProfile: Calling initializeLayout to update header.");
                    initializeLayout();
                } else {
                    console.warn("handleUpdateProfile: initializeLayout function not found.");
                    window.location.reload(); // Fallback nếu không tìm thấy hàm
                }
            }
            return true; // Thành công
        } catch (error) {
            // Lỗi đã được xử lý (alert + logout nếu 401/403) trong authenticatedFetch
            console.error("Lỗi cập nhật profile:", error);
            // Không cần alert lại ở đây nếu đã alert trong authenticatedFetch
            return false; // Thất bại
        }
    };

    // Hàm cập nhật mật khẩu (dùng authenticatedFetch)
    const handleUpdatePassword = async (payload) => {
        try {
            // Kiểm tra authenticatedFetch tồn tại
            if (typeof authenticatedFetch !== "function") throw new Error("Lỗi cấu hình (authenticatedFetch missing).");

            const result = await authenticatedFetch(`/smartcontent-app/api/settings.php`, {
                method: "POST",
                body: JSON.stringify({ action: "update_password", ...payload }),
            });
            alert(result.message);
            return result.success; // Trả về true/false
        } catch (error) {
            console.error("Lỗi cập nhật mật khẩu:", error);
            return false; // Thất bại
        }
    };

    // --- Event Listeners ---
    if (profileForm) {
        profileForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            // Lấy giá trị từ input elements đã khai báo ở đầu
            const name = profileNameInput ? profileNameInput.value : "";
            const phone = profilePhoneInput ? profilePhoneInput.value : "";
            const email = emailInput ? emailInput.value : "";
            await handleUpdateProfile({ name, phone, email });
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            const current_password = currentPasswordInput ? currentPasswordInput.value : "";
            const new_password = newPasswordInput ? newPasswordInput.value : "";
            const confirm_password = confirmPasswordInput ? confirmPasswordInput.value : "";

            if (new_password.length < 6) {
                alert("Mật khẩu mới phải có ít nhất 6 ký tự.");
                return;
            }
            if (new_password !== confirm_password) {
                alert("Mật khẩu mới không khớp.");
                return;
            }
            if (!current_password) {
                alert("Vui lòng nhập mật khẩu hiện tại.");
                return;
            }

            const success = await handleUpdatePassword({ current_password, new_password });
            if (success) {
                // Chỉ reset form nếu thành công
                e.target.reset();
            }
        });
    }

    // --- Tab Switching Logic ---
    const tabButtons = document.querySelectorAll(".settings-tab-button");
    const tabPanes = document.querySelectorAll(".settings-tab-pane");
    if (tabButtons.length > 0 && tabPanes.length > 0) {
        tabButtons.forEach((button) => {
            button.addEventListener("click", () => {
                const tab = button.dataset.tab;
                // Cập nhật giao diện nút
                tabButtons.forEach((btn) => {
                    btn.classList.remove("text-white", "bg-slate-700");
                    btn.classList.add("text-gray-300", "hover:text-white", "hover:bg-slate-700/50");
                });
                button.classList.add("text-white", "bg-slate-700");
                button.classList.remove("text-gray-300");
                // Ẩn/hiện nội dung tab
                tabPanes.forEach((pane) => {
                    if (pane.id === `${tab}-tab-content`) {
                        pane.classList.remove("hidden");
                    } else {
                        pane.classList.add("hidden");
                    }
                });
            });
        });
    }

    // --- AI Provider Toggles Logic ---
    const aiProviderToggles = document.querySelectorAll(".ai-provider-toggle");
    const geminiSettings = document.getElementById("gemini-settings");
    const openaiSettings = document.getElementById("openai-settings");

    const setActiveProvider = (provider) => {
        // Cập nhật giao diện nút toggle
        aiProviderToggles.forEach((btn) => {
            btn.classList.remove("bg-blue-600", "text-white");
            btn.classList.add("text-gray-300");
        });
        const activeBtn = document.querySelector(`.ai-provider-toggle[data-provider="${provider}"]`);
        if (activeBtn) {
            activeBtn.classList.add("bg-blue-600", "text-white");
            activeBtn.classList.remove("text-gray-300");
        }
        // Ẩn/hiện phần cài đặt tương ứng
        if (provider === "gemini") {
            if (geminiSettings) geminiSettings.classList.remove("hidden");
            if (openaiSettings) openaiSettings.classList.add("hidden");
        } else {
            if (geminiSettings) geminiSettings.classList.add("hidden");
            if (openaiSettings) openaiSettings.classList.remove("hidden");
        }
    };

    if (aiProviderToggles.length > 0) {
        aiProviderToggles.forEach((toggle) => {
            toggle.addEventListener("click", () => {
                const provider = toggle.dataset.provider;
                setActiveProvider(provider);
                // Lưu lựa chọn vào localStorage (ví dụ)
                // localStorage.setItem("aiProviderPreference", provider);
            });
        });
        // Lấy lựa chọn đã lưu (nếu có)
        // const savedProvider = localStorage.getItem("aiProviderPreference") || "gemini";
        // setActiveProvider(savedProvider);
        setActiveProvider("gemini"); // Hoặc mặc định luôn là gemini
    }

    // --- Initial Load ---
    // Đảm bảo authenticatedFetch sẵn sàng trước khi gọi
    if (typeof authenticatedFetch === "function") {
        fetchSettings();
    } else {
        console.error("settings.js: authenticatedFetch not available on initial load! Retrying...");
        setTimeout(() => {
            if (typeof authenticatedFetch === "function") {
                fetchSettings();
            } else {
                console.error("settings.js: authenticatedFetch STILL not available after timeout!");
                alert("Lỗi tải trang cài đặt. Vui lòng thử lại.");
            }
        }, 200);
    }
});
