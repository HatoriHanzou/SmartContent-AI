document.addEventListener("DOMContentLoaded", () => {
    const adminPage = document.getElementById("admin-tabs");
    if (!adminPage) return;

    let adminData = { plans: [], licenses: [], users: [] };
    let editingUser = null;
    let editingPlan = null;

    // ==== DOM ELEMENTS ====
    const plansTableBody = document.getElementById("plans-table-body");
    const licenseTableBody = document.getElementById("license-table-body");
    const usersTableBody = document.getElementById("users-table-body");
    const planForLicenseSelect = document.getElementById("plan-for-license");
    const licenseForUserSelect = document.getElementById("license-for-user");
    const licenseSearchInput = document.getElementById("license-search-input");
    const licenseStatusFilter = document.getElementById("license-status-filter");
    const userSearchInput = document.getElementById("user-search-input");
    const modalOverlay = document.getElementById("modal-overlay");
    const addUserModal = document.getElementById("add-user-modal");
    const planModal = document.getElementById("plan-modal");
    const planForm = document.getElementById("plan-form");
    const addPlanBtn = document.getElementById("add-plan-btn");
    const savePlanBtn = document.getElementById("save-plan-btn");
    const createLicenseBtn = document.getElementById("create-license-btn");

    // ==== TOAST ====
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
        setTimeout(() => (toast.style.opacity = "1"), 50);
        setTimeout(() => {
            toast.style.opacity = "0";
            setTimeout(() => toast.remove(), 500);
        }, 3000);
    };

    // ==== SET BUTTON LOADING  ====
    const setButtonLoading = (button, isLoading) => {
        if (!button) return;
        if (isLoading) {
            // Lưu nội dung HTML gốc của nút (bao gồm cả icon)
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }
            button.disabled = true;
            // Hiển thị icon spinner
            button.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i>Đang xử lý...`;
        } else {
            // Khôi phục nội dung HTML gốc
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                // Xóa attribute để sẵn sàng cho lần nhấp tiếp theo
                delete button.dataset.originalHtml;
            }
            button.disabled = false;
        }
    };

    // ==== LOAD DATA ====
    const loadAdminData = async () => {
        try {
            const res = await authenticatedFetch(`/smartcontent-app/api/admin.php?action=get_all_data`, {
                method: "GET",
            });
            if (!res || !res.success) throw new Error(res?.message || "Lỗi API.");
            adminData = res;
            renderPlans();
            renderLicenses();
            renderUsers();
            updateDropdowns();
        } catch (err) {
            console.error("loadAdminData error:", err);
            showToast("Không thể tải dữ liệu quản trị.", "error");
        }
    };

    // ==== RENDER PLANS ====
    const renderPlans = () => {
        plansTableBody.innerHTML = "";
        if (!adminData.plans.length) {
            plansTableBody.innerHTML = `<tr><td colspan="5" class="text-center py-6 text-gray-400">Chưa có gói cước nào.</td></tr>`;
            return;
        }
        adminData.plans.forEach((p) => {
            const price = new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(p.price);
            const limit = p.article_limit == -1 ? "Không giới hạn" : `${p.article_limit}/${p.type}`;
            plansTableBody.insertAdjacentHTML(
                "beforeend",
                `<tr class="border-b border-slate-700" data-id="${p.id}">
                    <td class="px-6 py-4">${p.name}</td>
                    <td class="px-6 py-4">${p.type}</td>
                    <td class="px-6 py-4">${price}</td>
                    <td class="px-6 py-4">${limit}</td>
                    <td class="px-6 py-4 text-center">
                        <button class="edit-plan-btn text-gray-400 hover:text-blue-400 p-2"><i class="fas fa-pencil-alt"></i></button>
                        <button class="delete-plan-btn text-gray-400 hover:text-red-400 p-2"><i class="fas fa-trash-alt"></i></button>
                    </td>
                </tr>`
            );
        });
    };

    // ==== RENDER LICENSES ====
    const renderLicenses = () => {
        licenseTableBody.innerHTML = "";
        const search = (licenseSearchInput?.value || "").toLowerCase().trim();
        const statusFilterValue = licenseStatusFilter?.value || "all";
        const normalize = (str) => (str || "").toLowerCase().replace(/[-_\s]/g, "");

        const filtered = adminData.licenses.filter((lic) => {
            let matchStatus = true;
            if (statusFilterValue === "assigned") matchStatus = lic.status === "Active";
            else if (statusFilterValue === "unassigned") matchStatus = lic.status === "NotActivated";
            const matchSearch =
                !search ||
                normalize(lic.license_key).includes(normalize(search)) ||
                (lic.plan_name && normalize(lic.plan_name).includes(normalize(search))) ||
                (lic.user_name && normalize(lic.user_name).includes(normalize(search)));
            return matchStatus && matchSearch;
        });

        if (!filtered.length) {
            licenseTableBody.innerHTML = `<tr><td colspan="5" class="text-center py-6 text-gray-400">Không tìm thấy license nào.</td></tr>`;
            return;
        }

        filtered.forEach((lic) => {
            const userText = lic.user_id ? lic.user_name || "Đã gán" : '<span class="text-gray-400">Chưa gán</span>';
            const statusClass =
                lic.status === "Active" ? "text-green-300 bg-green-600/20" : "text-yellow-300 bg-yellow-600/20";
            licenseTableBody.insertAdjacentHTML(
                "beforeend",
                `<tr class="border-b border-slate-700" data-id="${lic.id}">
                    <td class="px-6 py-4 font-mono">${lic.license_key}</td>
                    <td class="px-6 py-4">${lic.plan_name || "N/A"}</td>
                    <td class="px-6 py-4">${userText}</td>
                    <td class="px-6 py-4"><span class="px-2 py-1 rounded-full ${statusClass}">${lic.status}</span></td>
                    <td class="px-6 py-4 text-center">
                        <button class="delete-license-btn text-gray-400 hover:text-red-400 p-2"><i class="fas fa-trash-alt"></i></button>
                    </td>
                </tr>`
            );
        });
    };

    // ==== RENDER USERS ====
    const renderUsers = () => {
        usersTableBody.innerHTML = "";
        const search = (userSearchInput?.value || "").toLowerCase().trim();
        const normalize = (str) => (str || "").toLowerCase().replace(/[-_\s]/g, "");
        const filtered = adminData.users.filter(
            (u) =>
                !search ||
                normalize(u.name).includes(normalize(search)) ||
                normalize(u.email).includes(normalize(search)) ||
                normalize(u.phone).includes(normalize(search))
        );

        if (!filtered.length) {
            usersTableBody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-gray-400">Không tìm thấy người dùng nào.</td></tr>`;
            return;
        }

        filtered.forEach((u) => {
            const statusBadge =
                u.status === "Active"
                    ? `<span class="px-2 py-1 rounded-full bg-green-600/20 text-green-300">${u.status}</span>`
                    : `<span class="px-2 py-1 rounded-full bg-yellow-600/20 text-yellow-300">${
                          u.status || "Inactive"
                      }</span>`;
            usersTableBody.insertAdjacentHTML(
                "beforeend",
                `<tr class="border-b border-slate-700" data-id="${u.id}">
                    <td class="px-6 py-4">${u.id}</td>
                    <td class="px-6 py-4">${u.name}</td>
                    <td class="px-6 py-4">
                        <div>${u.email}</div>
                        <div class="text-gray-400 text-xs">${u.phone || "-"}</div>
                    </td>
                    <td class="px-6 py-4">${u.role}</td>
                    <td class="px-6 py-4">${u.license_key || "<span class='text-gray-400'>Chưa gán</span>"}</td>
                    <td class="px-6 py-4">${statusBadge}</td>
                    <td class="px-6 py-4 text-center">
                        <button class="edit-user-btn text-gray-400 hover:text-blue-400 p-2"><i class="fas fa-pencil-alt"></i></button>
                        <button class="delete-user-btn text-gray-400 hover:text-red-400 p-2"><i class="fas fa-trash-alt"></i></button>
                    </td>
                </tr>`
            );
        });
    };

    // ==== UPDATE DROPDOWNS ====
    const updateDropdowns = () => {
        if (planForLicenseSelect) {
            planForLicenseSelect.innerHTML = "";
            if (!adminData.plans.length) {
                planForLicenseSelect.innerHTML = "<option disabled>Chưa có gói</option>";
                planForLicenseSelect.disabled = true;
            } else {
                adminData.plans.forEach((p) => {
                    const opt = document.createElement("option");
                    opt.value = p.id;
                    opt.textContent = `${p.name} (${p.type})`;
                    planForLicenseSelect.appendChild(opt);
                });
            }
        }

        if (licenseForUserSelect) {
            licenseForUserSelect.innerHTML = '<option value="">Không gán giấy phép</option>';
            adminData.licenses
                .filter((lic) => !lic.user_id)
                .forEach((lic) => {
                    const opt = document.createElement("option");
                    opt.value = lic.id;
                    opt.textContent = `${lic.license_key} (${lic.plan_name || "N/A"})`;
                    licenseForUserSelect.appendChild(opt);
                });
        }
    };

    // ==== GENERATE LICENSE KEY ====
    const generateLicenseKey = () => {
        const chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
        let result = "SCAI-";
        for (let i = 0; i < 4; i++) {
            for (let j = 0; j < 4; j++) result += chars.charAt(Math.floor(Math.random() * chars.length));
            if (i < 3) result += "-";
        }
        return result;
    };

    // ==== CREATE LICENSE ====
    createLicenseBtn?.addEventListener("click", async () => {
        const planSelect = document.getElementById("plan-for-license");
        if (!planSelect.value) return showToast("Vui lòng chọn gói cước trước khi tạo License.", "warning");

        setButtonLoading(createLicenseBtn, true); // <-- BẬT LOADING
        try {
            const payload = { action: "add_license", plan_id: planSelect.value, key: generateLicenseKey() };
            const res = await fetch("/smartcontent-app/api/admin.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) {
                showToast("✅ " + data.message, "success");
                await loadAdminData();
            } else showToast("❌ " + data.message, "error");
        } catch {
            showToast("❌ Lỗi khi tạo License.", "error");
        } finally {
            setButtonLoading(createLicenseBtn, false); // <-- TẮT LOADING
        }
    });

    // ==== DELETE LICENSE ====
    licenseTableBody?.addEventListener("click", async (e) => {
        const btn = e.target.closest(".delete-license-btn");
        if (!btn) return;
        const id = btn.closest("tr")?.dataset?.id;
        if (!confirm("Bạn có chắc muốn xóa License này?")) return;

        setButtonLoading(btn, true); // <-- BẬT LOADING
        try {
            const res = await fetch("/smartcontent-app/api/admin.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "delete_license", id }),
            });
            const data = await res.json();
            if (data.success) {
                showToast("🗑️ " + data.message, "success");
                await loadAdminData();
            } else showToast("❌ " + data.message, "error");
        } catch {
            showToast("❌ Lỗi khi xóa License.", "error");
        } finally {
            setButtonLoading(btn, false); // <-- TẮT LOADING
        }
    });

    // ==== ADD USER ====
    document.getElementById("add-user-btn")?.addEventListener("click", () => {
        editingUser = null;
        document.getElementById("user-form")?.reset();
        document.getElementById("user-modal-title").textContent = "Thêm người dùng";
        document.getElementById("user-password-field").classList.remove("hidden");
        addUserModal.classList.remove("hidden");
        modalOverlay.classList.remove("hidden");
    });

    // ==== EDIT USER ====
    usersTableBody?.addEventListener("click", (e) => {
        // 1. Check nút SỬA
        const editBtn = e.target.closest(".edit-user-btn");
        if (editBtn) {
            const userId = editBtn.closest("tr")?.dataset?.id;
            const user = adminData.users.find((u) => u.id == userId);
            if (!user) return showToast("Không tìm thấy dữ liệu người dùng.", "error");

            editingUser = user;
            document.getElementById("user-modal-title").textContent = "Chỉnh sửa người dùng";
            document.getElementById("user-name").value = user.name || "";
            document.getElementById("user-email").value = user.email || "";
            document.getElementById("user-phone").value = user.phone || "";
            document.getElementById("user-role").value = user.role || "User";
            document.getElementById("user-password").value = "";
            document.getElementById("user-password-field").classList.add("hidden");

            updateDropdowns();
            const userLicense = adminData.licenses.find((lic) => lic.user_id == user.id);
            if (userLicense) {
                const optExists = licenseForUserSelect.querySelector(`option[value="${userLicense.id}"]`);
                if (!optExists) {
                    const opt = document.createElement("option");
                    opt.value = userLicense.id;
                    opt.textContent = `${userLicense.license_key} (Đã gán cho user này)`;
                    licenseForUserSelect.appendChild(opt);
                }
                licenseForUserSelect.value = userLicense.id;
            } else {
                licenseForUserSelect.value = "";
            }

            addUserModal.classList.remove("hidden");
            modalOverlay.classList.remove("hidden");
            showToast("📝 Đang chỉnh sửa người dùng: " + user.name, "info");
            return; // Dừng lại sau khi xử lý "edit"
        }

        // 2. Check nút XÓA (PHẦN BỔ SUNG)
        const deleteBtn = e.target.closest(".delete-user-btn");
        if (deleteBtn) {
            const userId = deleteBtn.closest("tr")?.dataset?.id;
            const user = adminData.users.find((u) => u.id == userId); // Tìm user để lấy tên
            const confirmMsg = user
                ? `Bạn có chắc muốn xóa người dùng "${user.name}"? Mọi license gán cho họ sẽ bị thu hồi.`
                : "Bạn có chắc muốn xóa người dùng này?";

            if (userId && confirm(confirmMsg)) {
                handleDeleteUser(userId, deleteBtn);
            }
            return; // Dừng lại sau khi xử lý "delete"
        }
    });

    // ==== CLOSE MODAL ====
    document.querySelectorAll(".modal-close-btn").forEach((btn) =>
        btn.addEventListener("click", () => {
            addUserModal.classList.add("hidden");
            planModal.classList.add("hidden"); // <-- THÊM DÒNG NÀY
            modalOverlay.classList.add("hidden");
            editingUser = null;
            editingPlan = null; // <-- THÊM DÒNG NÀY
            document.getElementById("user-password-field").classList.remove("hidden");
        })
    );

    // ==== SAVE USER ====
    document.getElementById("save-user-btn")?.addEventListener("click", async (e) => {
        e.preventDefault();
        const btn = document.getElementById("save-user-btn");
        const payload = {
            /* ... (giữ nguyên payload) ... */ action: editingUser ? "update_user" : "add_user",
            id: editingUser?.id || null,
            name: document.getElementById("user-name").value.trim(),
            email: document.getElementById("user-email").value.trim(),
            phone: document.getElementById("user-phone").value.trim(),
            role: document.getElementById("user-role").value.trim(),
            password: document.getElementById("user-password").value.trim(),
            license_id: document.getElementById("license-for-user").value || 0,
        };
        if (!payload.name || !payload.email) return showToast("⚠️ Vui lòng nhập đầy đủ thông tin.", "warning");
        if (!editingUser && !payload.password) return showToast("⚠️ Vui lòng nhập mật khẩu.", "warning");

        setButtonLoading(btn, true); // <-- BẬT LOADING
        try {
            const res = await fetch("/smartcontent-app/api/admin.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) {
                showToast("✅ " + data.message, "success");
                addUserModal.classList.add("hidden");
                modalOverlay.classList.add("hidden");
                await loadAdminData();
            } else showToast("❌ " + data.message, "error");
        } catch {
            showToast("❌ Lỗi khi lưu người dùng.", "error");
        } finally {
            setButtonLoading(btn, false); // <-- TẮT LOADING
        }
    });

    // ==== DELETE USER ====
    async function handleDeleteUser(id, btn) {
        // <-- Thêm 'btn'
        setButtonLoading(btn, true); // <-- BẬT LOADING
        try {
            const res = await fetch("/smartcontent-app/api/admin.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "delete_user", id }),
            });
            const data = await res.json();
            if (data.success) {
                showToast("🗑️ " + data.message, "success");
                await loadAdminData(); // Tải lại toàn bộ dữ liệu
            } else showToast("❌ " + data.message, "error");
        } catch (err) {
            showToast("❌ Lỗi khi xóa người dùng.", "error");
        } finally {
            setButtonLoading(btn, false); // <-- TẮT LOADING
        }
    }

    // ==== ADD PLAN ====
    addPlanBtn?.addEventListener("click", () => {
        editingPlan = null;
        planForm?.reset();
        document.getElementById("plan-modal-title").textContent = "Thêm Gói cước";
        planModal.classList.remove("hidden");
        modalOverlay.classList.remove("hidden");
    });

    // ==== EDIT/DELETE PLAN LISTENER ====
    plansTableBody?.addEventListener("click", (e) => {
        // 1. Check nút SỬA
        const editBtn = e.target.closest(".edit-plan-btn");
        if (editBtn) {
            const planId = editBtn.closest("tr")?.dataset?.id;
            const plan = adminData.plans.find((p) => p.id == planId);
            if (!plan) return showToast("Không tìm thấy gói cước.", "error");

            editingPlan = plan;
            document.getElementById("plan-modal-title").textContent = "Chỉnh sửa Gói cước";
            document.getElementById("plan-name").value = plan.name;
            document.getElementById("plan-type").value = plan.type;
            document.getElementById("plan-price").value = plan.price;
            document.getElementById("plan-limit").value = plan.article_limit;

            planModal.classList.remove("hidden");
            modalOverlay.classList.remove("hidden");
            return;
        }

        // 2. Check nút XÓA
        const deleteBtn = e.target.closest(".delete-plan-btn");
        if (deleteBtn) {
            const planId = deleteBtn.closest("tr")?.dataset?.id;
            const plan = adminData.plans.find((p) => p.id == planId);
            const confirmMsg = plan ? `Bạn có chắc muốn xóa gói "${plan.name}"?` : "Bạn có chắc muốn xóa gói này?";

            if (planId && confirm(confirmMsg)) {
                handleDeletePlan(planId, deleteBtn);
            }
        }
    });

    // ==== SAVE PLAN (ADD/EDIT) ====
    savePlanBtn?.addEventListener("click", async (e) => {
        e.preventDefault();
        const payload = {
            action: editingPlan ? "update_plan" : "add_plan",
            id: editingPlan?.id || null,
            name: document.getElementById("plan-name").value.trim(),
            type: document.getElementById("plan-type").value.trim(),
            price: document.getElementById("plan-price").value.trim(),
            article_limit: document.getElementById("plan-limit").value.trim(),
        };
        if (!payload.name || !payload.price || !payload.article_limit) {
            return showToast("⚠️ Vui lòng nhập đầy đủ thông tin.", "warning");
        }

        setButtonLoading(savePlanBtn, true); // <-- BẬT LOADING
        try {
            const res = await fetch("/smartcontent-app/api/admin.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) {
                showToast("✅ " + data.message, "success");
                planModal.classList.add("hidden");
                modalOverlay.classList.add("hidden");
                await loadAdminData();
            } else showToast("❌ " + data.message, "error");
        } catch {
            showToast("❌ Lỗi khi lưu gói cước.", "error");
        } finally {
            setButtonLoading(savePlanBtn, false); // <-- TẮT LOADING
        }
    });

    // ==== DELETE PLAN (FUNCTION) ====
    async function handleDeletePlan(id, btn) {
        // <-- Thêm 'btn'
        setButtonLoading(btn, true); // <-- BẬT LOADING
        try {
            const res = await fetch("/smartcontent-app/api/admin.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "delete_plan", id }),
            });
            const data = await res.json();
            if (data.success) {
                showToast("🗑️ " + data.message, "success");
                await loadAdminData();
            } else showToast("❌ " + data.message, "error");
        } catch (err) {
            showToast("❌ Lỗi khi xóa gói cước.", "error");
        } finally {
            setButtonLoading(btn, false); // <-- TẮT LOADING
        }
    }

    // ==== EVENT TÌM KIẾM & LỌC ====
    licenseSearchInput?.addEventListener("input", () => renderLicenses());
    licenseStatusFilter?.addEventListener("change", () => renderLicenses());
    userSearchInput?.addEventListener("input", () => renderUsers());

    // ==== INIT ====
    if (typeof authenticatedFetch === "function") loadAdminData();
    else setTimeout(loadAdminData, 300);
});
