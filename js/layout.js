// Hàm đăng xuất chung
const logoutUser = () => {
    console.log("logoutUser called"); // Log
    localStorage.removeItem("authToken");
    localStorage.removeItem("currentUser");
    localStorage.removeItem("activationUserId");
    localStorage.removeItem("pendingActivationUserId");
    // Chuyển về trang đăng nhập
    window.location.href = "index.html";
};

// Hàm gọi API chung (có xử lý lỗi 401/403 và đăng xuất)
const authenticatedFetch = async (url, options = {}) => {
    console.log("authenticatedFetch: Called for URL:", url); // LOG 1: Hàm được gọi
    const token = localStorage.getItem("authToken");
    if (!token) {
        console.warn("authenticatedFetch: Missing auth token, logging out.");
        alert("Phiên đăng nhập không hợp lệ hoặc đã hết hạn. Vui lòng đăng nhập lại.");
        logoutUser();
        throw new Error("Missing auth token"); // Ném lỗi để dừng hàm gọi
    }

    const defaultHeaders = {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`,
    };
    // Đảm bảo options.headers không bị ghi đè nếu đã tồn tại
    options.headers = { ...(options.headers || {}), ...defaultHeaders };

    let response; // Khai báo response ở ngoài để có thể log lỗi
    try {
        console.log(`authenticatedFetch: Making request to ${url}`, options); // Log request
        response = await fetch(url, options);
        console.log(`authenticatedFetch: Received response status ${response.status} from ${url}`); // Log status

        // Xử lý lỗi 401 (Unauthorized - Token sai/hết hạn) và 403 (Forbidden - License ko hợp lệ)
        if (response.status === 401 || response.status === 403) {
            let errorResult = { message: `Lỗi ${response.status}. Vui lòng thử lại.` }; // Thông báo mặc định
            try {
                // Thử đọc JSON lỗi từ API
                errorResult = await response.json();
                console.log(`authenticatedFetch: Received ${response.status} error JSON:`, errorResult); // Log lỗi
            } catch (e) {
                const responseText = await response.text(); // Đọc text nếu không phải JSON
                console.error(`authenticatedFetch: Could not parse JSON error (${response.status}):`, responseText); // Log lỗi HTML nếu có
                errorResult.message = `Lỗi ${response.status}. Phản hồi không hợp lệ từ máy chủ.`;
            }
            alert(errorResult.message || `Lỗi ${response.status}`); // Hiển thị thông báo lỗi
            logoutUser(); // **Đăng xuất ngay lập tức**
            throw new Error(`Authentication/Authorization Error: ${response.status}`); // Ném lỗi để dừng hàm gọi
        }

        // Xử lý lỗi server khác (ví dụ 500) hoặc lỗi mạng (response.ok = false)
        if (!response.ok) {
            const responseText = await response.text();
            console.error(`authenticatedFetch: Server error ${response.status}:`, responseText);
            let errorJson = null;
            try {
                errorJson = JSON.parse(responseText);
            } catch (e) {} // Thử parse lỗi JSON (nếu có)
            const errorMessage = errorJson?.message || `Lỗi máy chủ (${response.status}). Vui lòng thử lại sau.`;
            alert(errorMessage);
            // Không logout ngay khi lỗi 500, user có thể thử lại
            throw new Error(`Server Error: ${response.status}`);
        }

        // Nếu response.ok, đọc và parse JSON
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error("authenticatedFetch: Invalid JSON received:", responseText);
            alert(`Lỗi phản hồi từ máy chủ (dữ liệu không hợp lệ).`);
            throw new Error(`Invalid JSON response. Status: ${response.status}`);
        }

        // Kiểm tra lỗi logic từ API (success: false) sau khi đã parse JSON thành công
        if (data.success === false) {
            // Kiểm tra === false rõ ràng
            console.warn("authenticatedFetch: API returned success:false", data);
            throw new Error(data.message || `Lỗi không xác định từ API (success: false).`);
        }

        console.log(`authenticatedFetch: Success from ${url}`, data); // Log success data
        return data; // Thành công
    } catch (error) {
        // Chỉ hiển thị lỗi chung nếu chưa bị xử lý (401/403/Parse/Server)
        if (
            !error.message.startsWith("Auth Error") &&
            !error.message.startsWith("Invalid JSON response") &&
            !error.message.startsWith("Server Error")
        ) {
            console.error("authenticatedFetch: Network or other error:", error);
            alert(`Lỗi kết nối hoặc xử lý: ${error.message}`);
        }
        // Ném lại lỗi để các hàm gọi có thể biết và dừng lại nếu cần
        throw error;
    }
};
console.log("layout.js: authenticatedFetch function defined."); // LOG 4: Hàm đã được định nghĩa

// --- Các hàm tạo HTML ---
const createSidebarHTML = (user) => {
    const userRole = user ? user.role : "";
    const isAdmin = userRole && userRole.toLowerCase() === "admin";
    // Chỉ hiển thị link Quản trị nếu user là Admin
    const adminLink = isAdmin
        ? `
        <a href="admin.html" class="flex items-center py-2.5 px-4 rounded-lg transition duration-200 hover:bg-slate-700">
            <i class="fas fa-users-cog w-6 text-center"></i><span class="ml-3">Quản trị</span>
        </a>`
        : "";

    return `
<aside id="sidebar" class="sidebar fixed inset-y-0 left-0 z-30 w-64 bg-slate-800 p-4 transform md:relative md:transform-none sidebar-hidden md:sidebar-hidden-none flex flex-col">
    <div>
        <div class="flex items-center justify-between mb-10">
            <span class="text-xl font-bold flex items-center text-white">
                <i class="fas fa-brain mr-3 text-blue-400"></i>
                <span>SmartContent AI</span>
            </span>
             <button id="close-sidebar" class="md:hidden text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <nav class="space-y-2">
            <a href="articlewriter.html" class="flex items-center py-2.5 px-4 rounded-lg transition duration-200 hover:bg-slate-700">
                <i class="fas fa-pencil-alt w-6 text-center"></i><span class="ml-3">Viết bài</span>
            </a>
            <a href="keyword-research.html" class="flex items-center py-2.5 px-4 rounded-lg transition duration-200 hover:bg-slate-700">
                <i class="fas fa-search w-6 text-center"></i><span class="ml-3">Nghiên cứu từ khóa</span>
            </a>
            <a href="content-assistant.html" class="flex items-center py-2.5 px-4 rounded-lg transition duration-200 hover:bg-slate-700">
                <i class="fas fa-project-diagram w-6 text-center"></i><span class="ml-3">Trợ lý Nội dung</span>
            </a>
            <a href="content-calendar.html" class="flex items-center py-2.5 px-4 rounded-lg transition duration-200 hover:bg-slate-700">
                <i class="fas fa-calendar-alt w-6 text-center"></i><span class="ml-3">Lịch Nội dung</span>
            </a>
            <div class="pt-4 mt-4 border-t border-slate-700 space-y-2">
                 ${adminLink}
                <a href="settings.html" class="flex items-center py-2.5 px-4 rounded-lg transition duration-200 hover:bg-slate-700">
                    <i class="fas fa-cog w-6 text-center"></i><span class="ml-3">Cài đặt</span>
                </a>
            </div>
        </nav>
    </div>
    <div class="mt-auto">
         <a href="#" id="logout-btn" class="flex items-center py-2.5 px-4 rounded-lg transition duration-200 hover:bg-slate-700">
            <i class="fas fa-sign-out-alt w-6 text-center"></i><span class="ml-3">Đăng xuất</span>
        </a>
    </div>
</aside>`;
};

const createHeaderHTML = (pageTitle, user) => `
<header class="flex justify-between items-center p-4 bg-slate-800 border-b border-slate-700">
    <button id="open-sidebar" class="md:hidden text-gray-300">
        <i class="fas fa-bars"></i>
    </button>
    <h1 class="text-xl font-semibold text-white">${pageTitle || "Dashboard"}</h1>
    <div class="flex items-center space-x-4">
        <div class="text-right">
            <p class="text-sm font-medium text-white">${user ? user.name : "Guest"}</p>
            <p class="text-xs text-gray-400">${user ? user.email : ""}</p>
        </div>
        <img class="h-10 w-10 rounded-full object-cover bg-slate-700" src="https://placehold.co/100x100/1e293b/94a3b8?text=${
            user && user.name ? user.name.charAt(0).toUpperCase() : "G" // Thêm kiểm tra user.name
        }" alt="Avatar">
    </div>
</header>
`;

// --- Hàm chính để khởi tạo layout ---
function initializeLayout() {
    console.log("initializeLayout: Starting layout initialization."); // LOG 5: Bắt đầu layout
    const fullTitle = document.title;
    const pageTitle = fullTitle.split(" - ")[1] || "Dashboard";
    const userString = localStorage.getItem("currentUser");
    let user = null;
    try {
        // Thêm kiểm tra xem userString có phải JSON hợp lệ không
        if (userString && userString.startsWith("{") && userString.endsWith("}")) {
            user = JSON.parse(userString);
        } else if (userString) {
            console.warn("Invalid currentUser data in localStorage:", userString);
        }
    } catch (e) {
        console.error("Lỗi parse thông tin user từ localStorage:", e, userString);
        logoutUser(); // Nếu user data lỗi thì logout luôn
        return;
    }

    // Bảo vệ trang
    const publicPages = ["index.html", "register.html", "forgot-password.html", "activate.html", ""];
    let currentPage = window.location.pathname.split("/").pop();
    // Xử lý trường hợp URL không có file cụ thể (vd: /smartcontent-app/)
    if (currentPage === "" && window.location.pathname.endsWith("/")) {
        // Lấy tên file mặc định nếu có (ví dụ index.html) hoặc để trống nếu truy cập thư mục
        currentPage = "index.html"; // Giả định index.html là trang mặc định
    }

    // Kiểm tra trang hiện tại có cần đăng nhập không
    const requiresAuth = !publicPages.includes(currentPage);

    if (!user && requiresAuth) {
        console.log("User not logged in and page requires auth, redirecting to login from:", currentPage);
        window.location.href = "index.html";
        return; // Dừng lại
    }

    const sidebarPlaceholder = document.getElementById("sidebar-placeholder");
    const headerPlaceholder = document.getElementById("header-placeholder");

    // Chỉ chèn layout nếu có user VÀ trang yêu cầu đăng nhập (có placeholder)
    if (user && requiresAuth && (sidebarPlaceholder || headerPlaceholder)) {
        if (sidebarPlaceholder) {
            // Xóa sidebar cũ trước khi chèn mới (tránh trùng lặp ID)
            const existingSidebar = document.getElementById("sidebar");
            if (existingSidebar) existingSidebar.remove();
            sidebarPlaceholder.outerHTML = createSidebarHTML(user);
        } else {
            console.warn("Sidebar placeholder not found on page:", currentPage);
        }

        if (headerPlaceholder) {
            // Xóa header cũ trước khi chèn mới
            const existingHeader = document.querySelector("header");
            if (existingHeader) existingHeader.remove();
            headerPlaceholder.outerHTML = createHeaderHTML(pageTitle, user);
        } else {
            console.warn("Header placeholder not found on page:", currentPage);
        }

        // Gán sự kiện cho các nút sau khi chèn (kiểm tra element tồn tại)
        const sidebar = document.getElementById("sidebar");
        const openSidebarBtn = document.getElementById("open-sidebar");
        const closeSidebarBtn = document.getElementById("close-sidebar");
        const logoutBtn = document.getElementById("logout-btn");

        if (openSidebarBtn && sidebar) {
            openSidebarBtn.addEventListener("click", () => {
                sidebar.classList.remove("sidebar-hidden");
            });
        }
        if (closeSidebarBtn && sidebar) {
            closeSidebarBtn.addEventListener("click", () => {
                sidebar.classList.add("sidebar-hidden");
            });
        }
        if (logoutBtn) {
            logoutBtn.addEventListener("click", (e) => {
                e.preventDefault();
                logoutUser(); // Gọi hàm logout chung
            });
        } else {
            console.warn("Logout button not found after layout insertion.");
        }

        // Đánh dấu mục đang hoạt động
        if (sidebar) {
            const currentPath = window.location.pathname.split("/").pop() || "index.html"; // Thêm fallback
            const sidebarLinks = document.querySelectorAll("#sidebar nav a");
            sidebarLinks.forEach((link) => {
                const linkPath = link.getAttribute("href");
                if (linkPath === currentPath) {
                    link.classList.add("active-nav");
                } else {
                    link.classList.remove("active-nav");
                }
            });
        }
    } else if (user && !requiresAuth) {
        console.log("User is logged in but on a public page:", currentPage);
        // Tùy chọn: Có thể chuyển hướng về trang chính nếu user đã login mà vào trang public
        // window.location.href = "articlewriter.html";
    } else if (!user && !requiresAuth) {
        console.log("User not logged in and on a public page:", currentPage);
        // Không cần làm gì
    }
    console.log("initializeLayout: Finished layout initialization."); // LOG 6: Kết thúc layout
}

// Chạy hàm khởi tạo khi DOM sẵn sàng
document.addEventListener("DOMContentLoaded", () => {
    console.log("layout.js: DOMContentLoaded event fired."); // LOG 7: DOM Ready
    initializeLayout();
});
