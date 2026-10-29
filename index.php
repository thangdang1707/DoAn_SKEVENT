<?php
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');

$pdo = new PDO("mysql:host=127.0.0.1;dbname=event_xyz;charset=utf8mb4", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$message = '';
$error = '';
$now = date('Y-m-d H:i:s');

// ==============================================================
// XỬ LÝ BUSINESS RULES VÀ LOGIC
// ==============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // [2.1] Đăng nhập
    if ($action === 'login') {
        $stmt = $pdo->prepare("SELECT * FROM Users WHERE Email = ? AND Password = ?");
        $stmt->execute([trim($_POST['email']), trim($_POST['password'])]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $_SESSION['user_id'] = $user['UserID'];
            $_SESSION['role'] = $user['Role'];
            $_SESSION['name'] = $user['FullName'];
            $_SESSION['dept'] = $user['Department'];
            header("Location: index.php"); exit;
        } else {
            $error = "Sai thông tin đăng nhập!";
        }
    }
    
    if ($action === 'logout') { session_destroy(); header("Location: index.php"); exit; }

    if (isset($_SESSION['user_id'])) {
        // [3.4] NHÂN VIÊN ĐĂNG KÝ
        if ($action === 'register_event' && $_SESSION['role'] === 'Employee') {
            $eventID = (int)$_POST['event_id'];
            $userID = $_SESSION['user_id'];

            $ev = $pdo->prepare("SELECT * FROM Events WHERE EventID = ?");
            $ev->execute([$eventID]);
            $event = $ev->fetch(PDO::FETCH_ASSOC);

            $check = $pdo->prepare("SELECT * FROM Registrations WHERE EventID = ? AND UserID = ? AND Status = 'Registered'");
            $check->execute([$eventID, $userID]);

            if ($check->rowCount() > 0) {
                $error = "BR01: Bạn đã đăng ký sự kiện này rồi!";
            } elseif ($event['Registered'] >= $event['MaxUsers']) {
                $error = "BR02: Sự kiện đã đủ số lượng (Đã đầy)!";
            } elseif ($now > $event['Deadline']) {
                $error = "BR03: Đã hết hạn đăng ký!";
            } elseif ($event['TargetAudience'] !== 'All' && $event['TargetAudience'] !== $_SESSION['dept']) {
                $error = "Đối tượng tham gia không hợp lệ. Chỉ dành cho: " . $event['TargetAudience'];
            } else {
                $pdo->prepare("INSERT INTO Registrations (EventID, UserID) VALUES (?, ?)")->execute([$eventID, $userID]);
                $pdo->prepare("UPDATE Events SET Registered = Registered + 1 WHERE EventID = ?")->execute([$eventID]);
                $message = "Đăng ký thành công!";
            }
        }
        
        // [3.5] NHÂN VIÊN HỦY ĐĂNG KÝ
        if ($action === 'cancel_register' && $_SESSION['role'] === 'Employee') {
            $regID = (int)$_POST['reg_id'];
            $eventID = (int)$_POST['event_id'];
            
            $ev = $pdo->prepare("SELECT Deadline FROM Events WHERE EventID = ?");
            $ev->execute([$eventID]);
            $deadline = $ev->fetchColumn();

            if ($now > $deadline) {
                $error = "BR04: Bạn chỉ được hủy đăng ký TRƯỚC thời hạn quy định!";
            } else {
                // KIỂM TRA TRẠNG THÁI TRƯỚC KHI HỦY
                $checkReg = $pdo->prepare("SELECT Status FROM Registrations WHERE RegID = ? AND UserID = ?");
                $checkReg->execute([$regID, $_SESSION['user_id']]);
                $currentStatus = $checkReg->fetchColumn();

                // Chỉ thực hiện trừ sĩ số nếu trạng thái hiện tại đúng là đang "Registered"
                if ($currentStatus === 'Registered') {
                    $pdo->prepare("UPDATE Registrations SET Status = 'Cancelled' WHERE RegID = ? AND UserID = ?")->execute([$regID, $_SESSION['user_id']]);
                    // Dùng hàm GREATEST(0, ...) của MySQL để đảm bảo kết quả luôn >= 0
                    $pdo->prepare("UPDATE Events SET Registered = GREATEST(0, Registered - 1) WHERE EventID = ?")->execute([$eventID]);
                    $message = "Đã hủy đăng ký thành công!";
                } else {
                    $error = "Yêu cầu đã được xử lý từ trước, không thể hủy thêm!";
                }
            }
        }

        // [3.6] QUẢN LÝ SỰ KIỆN (ORGANIZER & ADMIN)
        if (in_array($_SESSION['role'], ['Organizer', 'Admin'])) {
            if ($action === 'create_event') { // BR06: Chỉ Organizer & Admin tạo được
                $pdo->prepare("INSERT INTO Events (Name, Description, EventTime, Location, OrganizerID, MaxUsers, Deadline, TargetAudience) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$_POST['name'], $_POST['desc'], $_POST['eventTime'], $_POST['location'], $_SESSION['user_id'], $_POST['maxUsers'], $_POST['deadline'], $_POST['audience']]);
                $message = "Tạo sự kiện thành công!";
            }
            if ($action === 'update_event') {
                $pdo->prepare("UPDATE Events SET Name=?, Description=?, EventTime=?, Location=?, MaxUsers=?, Deadline=?, TargetAudience=? WHERE EventID=?")
                    ->execute([$_POST['name'], $_POST['desc'], $_POST['eventTime'], $_POST['location'], $_POST['maxUsers'], $_POST['deadline'], $_POST['audience'], $_POST['event_id']]);
                $message = "Cập nhật thành công!";
            }
            if ($action === 'delete_event') {
                $id = (int)$_POST['event_id'];
                $ev = $pdo->prepare("SELECT EventTime FROM Events WHERE EventID = ?");
                $ev->execute([$id]);
                if ($now > $ev->fetchColumn()) {
                    $error = "BR05: Không thể xóa/hủy sự kiện ĐÃ BẮT ĐẦU hoặc ĐÃ DIỄN RA!";
                } else {
                    $pdo->prepare("UPDATE Events SET Status = 'Cancelled' WHERE EventID = ?")->execute([$id]);
                    $message = "Đã hủy sự kiện!";
                }
            }
        }
    }
}

// ==============================================================
// FETCH DỮ LIỆU RENDER
// ==============================================================
$events = $pdo->query("SELECT e.*, u.FullName as OrgName FROM Events e JOIN Users u ON e.OrganizerID = u.UserID ORDER BY e.EventID DESC")->fetchAll(PDO::FETCH_ASSOC);

$myEvents = []; 
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'Employee') {
    $myRegs = $pdo->prepare("SELECT r.RegID, e.EventID, e.Name, e.Location, e.Deadline, r.Status FROM Registrations r JOIN Events e ON r.EventID = e.EventID WHERE r.UserID = ? AND r.Status = 'Registered'");
    $myRegs->execute([$_SESSION['user_id']]);
    $myEvents = $myRegs->fetchAll(PDO::FETCH_ASSOC);
}

// Lấy danh sách đăng ký cho Organizer xem (Yêu cầu 3.7)
$participants = [];
if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['Organizer', 'Admin'])) {
    $participants = $pdo->query("SELECT r.*, u.FullName, u.Department, e.Name as EventName FROM Registrations r JOIN Users u ON r.UserID = u.UserID JOIN Events e ON r.EventID = e.EventID")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ Thống Đăng Ký Sự Kiện Nội Bộ XYZ</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-200 p-8 font-sans relative">
    
    <div class="max-w-6xl mx-auto space-y-8">
        
        <?php if(isset($_SESSION['user_id'])): ?>
            <div class="bg-white p-4 rounded shadow flex justify-between items-center border border-gray-300">
                <div class="font-bold text-blue-800 text-lg">
                    Xin chào, <?= htmlspecialchars($_SESSION['name']) ?> 
                    <span class="bg-blue-100 text-blue-800 text-sm px-2 py-1 rounded ml-2 border border-blue-300">Vai trò: <?= htmlspecialchars($_SESSION['role']) ?> | Phòng: <?= htmlspecialchars($_SESSION['dept']) ?></span>
                </div>
                <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded font-bold hover:bg-red-600">Đăng xuất</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if($message): ?><div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded font-bold text-center"><?= $message ?></div><?php endif; ?>
        <?php if($error): ?><div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded font-bold text-center"><?= $error ?></div><?php endif; ?>

        <!-- ============================================================== -->
        <!-- MÀN HÌNH 1: LOGIN -->
        <!-- ============================================================== -->
        <?php if(!isset($_SESSION['user_id'])): ?>
            <div>
                <h2 class="text-xl font-bold mb-4 text-gray-700">1. Login Screen</h2>
                <div class="bg-white p-8 rounded-lg shadow-md max-w-md border border-gray-300 mx-auto">
                    <h3 class="text-2xl font-extrabold text-center text-blue-700 mb-6">ĐĂNG NHẬP HỆ THỐNG XYZ</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <input type="email" name="email" placeholder="Email công ty..." required class="w-full mb-4 p-3 border rounded focus:ring-2 focus:ring-blue-500">
                        <input type="password" name="password" placeholder="Mật khẩu..." required class="w-full mb-6 p-3 border rounded focus:ring-2 focus:ring-blue-500">
                        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded hover:bg-blue-700">Submit (Đăng nhập)</button>
                    </form>
                </div>
            </div>

        <!-- ============================================================== -->
        <!-- MÀN HÌNH NHÂN VIÊN (EMPLOYEE) -->
        <!-- ============================================================== -->
        <?php elseif($_SESSION['role'] === 'Employee'): ?>
            <!-- 2. Event List -->
            <div>
                <h2 class="text-xl font-bold mb-4 text-gray-700">2. Xem Danh Sách Sự Kiện (Dashboard Nhân viên)</h2>
                <div class="bg-white p-6 rounded-lg shadow-md border border-gray-300">
                    <div class="grid grid-cols-3 gap-6">
                        <?php foreach ($events as $ev): 
                            $isOverdue = ($now > $ev['Deadline']);
                            $isFull = ($ev['Registered'] >= $ev['MaxUsers']);
                            $isCancelled = ($ev['Status'] === 'Cancelled');
                        ?>
                        <div class="border p-4 rounded bg-gray-50 flex flex-col justify-between relative overflow-hidden">
                            <?php if($isCancelled): ?><div class="absolute top-0 right-0 bg-gray-800 text-white text-xs font-bold px-2 py-1">Đã hủy</div>
                            <?php elseif($isOverdue): ?><div class="absolute top-0 right-0 bg-red-500 text-white text-xs font-bold px-2 py-1">Hết hạn ĐK</div><?php endif; ?>
                            
                            <div>
                                <h4 class="font-bold text-lg text-blue-800"><?= htmlspecialchars($ev['Name']) ?></h4>
                                <p class="text-sm text-gray-600 mt-1">📍 <?= htmlspecialchars($ev['Location']) ?> | ⏰ <?= date('d/m/Y H:i', strtotime($ev['EventTime'])) ?></p>
                                <p class="text-sm text-gray-600 mt-1">Người TC: <strong><?= htmlspecialchars($ev['OrgName']) ?></strong></p>
                                <p class="text-sm font-bold mt-1 <?= $isFull ? 'text-red-600' : 'text-green-600' ?>">Đã ĐK: <?= $ev['Registered'] ?>/<?= $ev['MaxUsers'] ?></p>
                            </div>
                            
                            <!-- Đổ dữ liệu JSON cho JS để hiển thị Detail -->
                            <?php $evJson = htmlspecialchars(json_encode($ev), ENT_QUOTES, 'UTF-8'); ?>
                            <button onclick="showDetail(<?= $evJson ?>, <?= $isOverdue ? 'true' : 'false' ?>, <?= $isCancelled ? 'true' : 'false' ?>)" 
                                    class="mt-4 w-full bg-blue-100 hover:bg-blue-200 text-blue-700 font-bold py-2 rounded text-sm transition">
                                Xem chi tiết & Đăng ký
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 3. Event Detail -->
            <div id="detailSection" class="hidden">
                <h2 class="text-xl font-bold mb-4 text-gray-700">3. Xem Chi Tiết Sự Kiện</h2>
                <div class="bg-white rounded-lg shadow-md border border-gray-300 overflow-hidden">
                    <div class="bg-blue-800 h-32 flex items-center justify-center">
                        <h3 id="dtBanner" class="text-white text-3xl font-bold uppercase tracking-wider">BANNER SỰ KIỆN</h3>
                    </div>
                    <div class="p-6">
                        <h3 id="dtName" class="text-2xl font-bold text-gray-800 mb-2">Tên sự kiện</h3>
                        <p class="text-gray-600 mb-4"><strong>Mô tả:</strong> <span id="dtDesc"></span></p>
                        <div class="grid grid-cols-2 gap-4 text-sm mb-6 bg-gray-50 p-4 rounded border">
                            <p>📍 <strong>Địa điểm:</strong> <span id="dtLoc"></span></p>
                            <p>⏰ <strong>Thời gian tổ chức:</strong> <span id="dtTime"></span></p>
                            <p>👤 <strong>Người tổ chức:</strong> <span id="dtOrg"></span></p>
                            <p>🎯 <strong>Đối tượng tham gia:</strong> <span id="dtAudience" class="font-bold text-blue-600"></span></p>
                            <p>⏳ <strong>Hạn chót ĐK:</strong> <span id="dtDead" class="text-red-600 font-bold"></span></p>
                            <p>👥 <strong>Trạng thái Sĩ số:</strong> <span id="dtReg"></span> / <span id="dtMax"></span> người</p>
                        </div>
                        <button id="btnOpenModal" onclick="openModal()" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-3 px-8 rounded-lg shadow-lg text-lg w-full uppercase">
                            Đăng ký tham gia ngay
                        </button>
                    </div>
                </div>
            </div>

            <!-- 5. My Events -->
            <div>
                <h2 class="text-xl font-bold mb-4 text-gray-700">5. Quản lý Đăng Ký (Sự kiện của tôi)</h2>
                <div class="bg-white p-6 rounded-lg shadow-md border border-gray-300">
                    <?php if(empty($myEvents)): ?> 
                        <p class="text-sm text-gray-500">Bạn chưa đăng ký tham gia sự kiện nào.</p> 
                    <?php endif; ?>
                    
                    <div class="space-y-4">
                        <?php foreach ($myEvents as $myEv): ?>
                        <div class="border p-4 rounded bg-gray-50 flex justify-between items-center">
                            <div>
                                <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($myEv['Name']) ?></h4>
                                <p class="text-sm text-gray-600">📍 <?= htmlspecialchars($myEv['Location']) ?> | Trạng thái: <span class="text-green-600 font-bold">Đã đăng ký</span></p>
                            </div>
                            <!-- Nút Hủy duy nhất tích hợp gọi Modal Đen mờ -->
                            <button type="button" onclick="openCancelModal(<?= $myEv['RegID'] ?>, <?= $myEv['EventID'] ?>, '<?= htmlspecialchars($myEv['Name'], ENT_QUOTES) ?>')" class="bg-red-500 text-white px-4 py-2 rounded font-bold hover:bg-red-600 shadow-md">
                                Hủy đăng ký
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 4. Register Modal (Popup Xác nhận) -->
            <div id="registerModal" class="hidden fixed inset-0 z-50 bg-black bg-opacity-60 flex items-center justify-center">
                <div class="bg-white w-96 p-6 rounded-lg shadow-2xl">
                    <h3 class="text-xl font-bold mb-4 text-center border-b pb-2">XÁC NHẬN ĐĂNG KÝ</h3>
                    <div class="bg-gray-100 p-4 rounded mb-4 text-sm space-y-2 border">
                        <p><strong>Sự kiện:</strong> <span id="modalEvName" class="text-blue-700 font-bold"></span></p>
                        <p><strong>Nhân viên:</strong> <?= htmlspecialchars($_SESSION['name']) ?></p>
                        <p><strong>Phòng ban:</strong> <?= htmlspecialchars($_SESSION['dept']) ?></p>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="register_event">
                        <input type="hidden" name="event_id" id="modalEvId" value="">
                        <div class="flex justify-between space-x-2">
                            <button type="button" onclick="closeModal()" class="w-1/2 bg-gray-300 py-2 rounded font-bold hover:bg-gray-400">Hủy</button>
                            <button type="submit" class="w-1/2 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700">Chốt Đăng Ký</button>
                        </div>
                    </form>
                </div>
            </div>
                <!-- Popup Xác Nhận Hủy Đăng Ký (UI Tailwind) -->
            <div id="cancelModal" class="hidden fixed inset-0 z-50 bg-black bg-opacity-60 flex items-center justify-center">
                <div class="bg-white w-96 p-6 rounded-lg shadow-2xl">
                    <h3 class="text-xl font-bold mb-4 text-center border-b pb-2 text-red-600">XÁC NHẬN HỦY THAM GIA</h3>
                    <div class="bg-gray-100 p-4 rounded mb-4 text-sm space-y-2 border">
                        <p>Sự kiện: <strong id="cancelModalEvName" class="text-blue-700"></strong></p>
                        <p class="text-red-500 italic">(Lưu ý: Chỉ được phép hủy trước Hạn chót đăng ký)</p>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="cancel_register">
                        <input type="hidden" name="reg_id" id="cancelModalRegId" value="">
                        <input type="hidden" name="event_id" id="cancelModalEvId" value="">
                        <div class="flex justify-between space-x-2">
                            <button type="button" onclick="closeCancelModal()" class="w-1/2 bg-gray-300 py-2 rounded font-bold hover:bg-gray-400">Không hủy</button>
                            <button type="submit" class="w-1/2 bg-red-600 text-white py-2 rounded font-bold hover:bg-red-700">Đồng ý Hủy</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                function showDetail(ev, isOverdue, isCancelled) {
                    document.getElementById('dtBanner').innerText = ev.Name;
                    document.getElementById('dtName').innerText = ev.Name;
                    document.getElementById('dtDesc').innerText = ev.Description;
                    document.getElementById('dtLoc').innerText = ev.Location;
                    document.getElementById('dtTime').innerText = ev.EventTime;
                    document.getElementById('dtDead').innerText = ev.Deadline;
                    document.getElementById('dtOrg').innerText = ev.OrgName;
                    document.getElementById('dtAudience').innerText = ev.TargetAudience;
                    document.getElementById('dtReg').innerText = ev.Registered;
                    document.getElementById('dtMax').innerText = ev.MaxUsers;
                    
                    let btn = document.getElementById('btnOpenModal');
                    if (isCancelled) {
                        btn.disabled = true; btn.innerText = "Sự kiện đã bị hủy"; btn.className = "bg-gray-800 text-white font-bold py-3 px-8 rounded-lg w-full uppercase cursor-not-allowed";
                    } else if (isOverdue) {
                        btn.disabled = true; btn.innerText = "Sự kiện đã hết hạn"; btn.className = "bg-gray-400 text-white font-bold py-3 px-8 rounded-lg w-full uppercase cursor-not-allowed";
                    } else if (ev.Registered >= ev.MaxUsers) {
                        btn.disabled = true; btn.innerText = "Sự kiện đã đầy sĩ số"; btn.className = "bg-gray-400 text-white font-bold py-3 px-8 rounded-lg w-full uppercase cursor-not-allowed";
                    } else {
                        btn.disabled = false; btn.innerText = "Đăng ký tham gia ngay"; btn.className = "bg-orange-500 hover:bg-orange-600 text-white font-bold py-3 px-8 rounded-lg shadow-lg w-full uppercase";
                    }

                    document.getElementById('modalEvId').value = ev.EventID;
                    document.getElementById('modalEvName').innerText = ev.Name;

                    const section = document.getElementById('detailSection');
                    section.classList.remove('hidden');
                    section.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                function openModal() { document.getElementById('registerModal').classList.remove('hidden'); }
                function closeModal() { document.getElementById('registerModal').classList.add('hidden'); }
                function openCancelModal(regId, evId, evName) {
                document.getElementById('cancelModalRegId').value = regId;
                document.getElementById('cancelModalEvId').value = evId;
                document.getElementById('cancelModalEvName').innerText = evName;
                document.getElementById('cancelModal').classList.remove('hidden');
                }

                function closeCancelModal() {
                document.getElementById('cancelModal').classList.add('hidden');
                }
            </script>


        <!-- ============================================================== -->
        <!-- MÀN HÌNH ORGANIZER VÀ ADMIN -->
        <!-- ============================================================== -->
        <?php elseif(in_array($_SESSION['role'], ['Organizer', 'Admin'])): ?>
            
            <!-- [Yêu cầu 3.9] Dashboard Thống kê -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-blue-600 text-white p-4 rounded shadow-md text-center">
                    <p class="text-sm font-bold uppercase">Tổng sự kiện</p>
                    <h3 class="text-3xl font-extrabold"><?= count($events) ?></h3>
                </div>
                <div class="bg-green-600 text-white p-4 rounded shadow-md text-center">
                    <p class="text-sm font-bold uppercase">Lượt đăng ký</p>
                    <h3 class="text-3xl font-extrabold"><?= count($participants) ?></h3>
                </div>
                <div class="bg-orange-500 text-white p-4 rounded shadow-md text-center">
                    <p class="text-sm font-bold uppercase">Người tham gia</p>
                    <h3 class="text-3xl font-extrabold">Theo dõi Participant</h3>
                </div>
            </div>

            <!-- 6. Event Management -->
            <div>
                <h2 class="text-xl font-bold mb-4 text-gray-700">6. Event Management (Tạo & Quản lý Sự kiện)</h2>
                <div class="bg-white p-6 rounded-lg shadow-md border border-gray-300">
                    
                    <div class="bg-gray-50 p-4 border rounded mb-6">
                        <h4 class="font-bold mb-4 text-blue-600 text-lg" id="formTitle">Tạo Sự Kiện Mới</h4>
                        <form method="POST" class="grid grid-cols-6 gap-4">
                            <input type="hidden" name="action" id="formAction" value="create_event">
                            <input type="hidden" name="event_id" id="eventId" value="">
                            
                            <div class="col-span-3">
                                <label class="block text-sm font-bold mb-1">Tên sự kiện <span class="text-red-500">*</span></label>
                                <input type="text" name="name" id="eventName" required class="w-full p-2 border rounded">
                            </div>
                            <div class="col-span-3">
                                <label class="block text-sm font-bold mb-1">Đối tượng (Phòng ban / All) <span class="text-red-500">*</span></label>
                                <input type="text" name="audience" id="eventAud" value="All" required class="w-full p-2 border rounded">
                            </div>
                            <div class="col-span-6">
                                <label class="block text-sm font-bold mb-1">Mô tả sự kiện <span class="text-red-500">*</span></label>
                                <textarea name="desc" id="eventDesc" required class="w-full p-2 border rounded" rows="2"></textarea>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-sm font-bold mb-1">Địa điểm <span class="text-red-500">*</span></label>
                                <input type="text" name="location" id="eventLoc" required class="w-full p-2 border rounded">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-sm font-bold mb-1">Thời gian tổ chức <span class="text-red-500">*</span></label>
                                <input type="datetime-local" name="eventTime" id="eventTime" required class="w-full p-2 border rounded">
                            </div>
                            <div class="col-span-1">
                                <label class="block text-sm font-bold mb-1">Hạn ĐK <span class="text-red-500">*</span></label>
                                <input type="datetime-local" name="deadline" id="eventDead" required class="w-full p-2 border rounded">
                            </div>
                            <div class="col-span-1">
                                <label class="block text-sm font-bold mb-1">Sĩ số <span class="text-red-500">*</span></label>
                                <input type="number" name="maxUsers" id="eventMax" required min="1" class="w-full p-2 border rounded">
                            </div>
                            <div class="col-span-6 flex justify-end gap-2 mt-2">
                                <button type="button" onclick="window.location.reload();" class="bg-gray-400 text-white px-6 py-2 rounded font-bold">Hủy / Làm mới</button>
                                <button type="submit" id="submitBtn" class="bg-green-600 text-white px-8 py-2 rounded font-bold hover:bg-green-700">Lưu Sự Kiện</button>
                            </div>
                        </form>
                    </div>

                    <table class="w-full text-left border-collapse border border-gray-200">
                        <thead class="bg-gray-100 text-sm">
                            <tr>
                                <th class="p-3 border">Tên sự kiện</th>
                                <th class="p-3 border text-center">Tổ chức / Hạn ĐK</th>
                                <th class="p-3 border text-center">Trạng thái</th>
                                <th class="p-3 border text-center">Sĩ số</th>
                                <th class="p-3 border text-center">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $ev): ?>
                            <tr class="hover:bg-gray-50 text-sm">
                                <td class="p-3 border font-bold"><?= htmlspecialchars($ev['Name']) ?></td>
                                <td class="p-3 border text-center">
                                    TC: <?= date('d/m/Y H:i', strtotime($ev['EventTime'])) ?><br>
                                    <span class="text-red-600 font-bold">Hạn: <?= date('d/m/Y H:i', strtotime($ev['Deadline'])) ?></span>
                                </td>
                                <td class="p-3 border text-center font-bold <?= $ev['Status']=='Cancelled'?'text-red-600':'text-green-600'?>"><?= $ev['Status'] ?></td>
                                <td class="p-3 border text-center font-bold text-blue-600"><?= $ev['Registered'] ?> / <?= $ev['MaxUsers'] ?></td>
                                <td class="p-3 border text-center space-x-2">
                                    <?php $evJson = htmlspecialchars(json_encode($ev), ENT_QUOTES, 'UTF-8'); ?>
                                    <button onclick="editEvent(<?= $evJson ?>)" class="text-blue-600 font-bold hover:underline">Sửa</button>
                                    <span class="text-gray-300">|</span>
                                    <form method="POST" class="inline" onsubmit="return confirm('Xóa/Hủy sự kiện này? (BR05: Không thể hủy nếu đã bắt đầu)');">
                                        <input type="hidden" name="action" value="delete_event">
                                        <input type="hidden" name="event_id" value="<?= $ev['EventID'] ?>">
                                        <button type="submit" class="text-red-600 font-bold hover:underline">Hủy SK</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- [Yêu cầu 3.7] Quản lý Danh sách Người Tham Gia -->
            <div>
                <h2 class="text-xl font-bold mb-4 text-gray-700">7. Quản lý Danh sách Người Tham Gia (Participants)</h2>
                <div class="bg-white p-6 rounded-lg shadow-md border border-gray-300">
                    <table class="w-full text-left border-collapse border border-gray-200">
                        <thead class="bg-gray-100 text-sm">
                            <tr><th class="p-3 border">Nhân viên</th><th class="p-3 border">Phòng ban</th><th class="p-3 border">Sự kiện ĐK</th><th class="p-3 border text-center">Trạng thái</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($participants as $p): ?>
                            <tr class="hover:bg-gray-50 text-sm">
                                <td class="p-3 border font-bold"><?= htmlspecialchars($p['FullName']) ?></td>
                                <td class="p-3 border"><?= htmlspecialchars($p['Department']) ?></td>
                                <td class="p-3 border text-blue-800 font-bold"><?= htmlspecialchars($p['EventName']) ?></td>
                                <td class="p-3 border text-center font-bold text-green-600"><?= htmlspecialchars($p['Status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <script>
                function editEvent(ev) {
                    document.getElementById('formAction').value = 'update_event';
                    document.getElementById('eventId').value = ev.EventID;
                    document.getElementById('eventName').value = ev.Name;
                    document.getElementById('eventDesc').value = ev.Description;
                    document.getElementById('eventLoc').value = ev.Location;
                    document.getElementById('eventAud').value = ev.TargetAudience;
                    document.getElementById('eventTime').value = ev.EventTime.replace(' ', 'T');
                    document.getElementById('eventDead').value = ev.Deadline.replace(' ', 'T');
                    document.getElementById('eventMax').value = ev.MaxUsers;
                    
                    document.getElementById('formTitle').innerText = 'Cập nhật Sự Kiện';
                    document.getElementById('submitBtn').className = 'bg-orange-500 text-white px-8 py-2 rounded font-bold';
                    document.getElementById('formTitle').scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>