<?php
/* =========================================================
   HUNTER ESPORTS – SINGLE FILE APPLICATION
   Route: ?action=payment|verify|success|rider_api|sms
   Default: Registration (index)
   ========================================================= */

/* ============== 1) CONFIG ============== */
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect("localhost", "hunteres_esports", "hunteres_esports", "hunteres_esports");
if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }
$conn->set_charset("utf8mb4");
date_default_timezone_set('Asia/Dhaka');
session_start();

$action = $_GET['action'] ?? '';

/* ============== 2) API: RIDER ============== */
if ($action === 'rider_api') {
    header('Content-Type: text/plain');
    $trxid  = strtoupper(trim($_GET['trxid'] ?? ''));
    $amount = trim($_GET['amount'] ?? '');
    $sender = trim($_GET['sender'] ?? '');

    if ($trxid === '' || $amount === '') { echo "Error"; exit; }
    $amount_val = (float)preg_replace('/[^\d.]/', '', $amount);

    $check = $conn->prepare("SELECT id FROM deve_rasel_id WHERE trxid = ?");
    $check->bind_param("s", $trxid);
    $check->execute();
    if ($check->get_result()->num_rows > 0) { $check->close(); echo "Error"; exit; }
    $check->close();

    $stmt = $conn->prepare("INSERT INTO deve_rasel_id (trxid, amount, sender) VALUES (?, ?, ?)");
    $stmt->bind_param("sds", $trxid, $amount_val, $sender);
    echo $stmt->execute() ? "Success" : "Error";
    $stmt->close(); $conn->close();
    exit;
}

/* ============== 3) API: SMS ============== */
if ($action === 'sms') {
    header('Content-Type: application/json');
    $amount_raw   = $_REQUEST['amount']   ?? '';
    $trxid_raw    = $_REQUEST['trxid']    ?? '';
    $user_key_raw = $_REQUEST['user_key'] ?? '';

    $amount   = preg_replace('/[^\d.]/', '', $amount_raw);
    $trxid    = strtoupper(trim($trxid_raw));
    $user_key = trim($user_key_raw);

    if (empty($amount) || empty($trxid)) {
        echo json_encode(["status"=>"error","message"=>"No data received. Please provide amount and trxid."]);
        exit;
    }

    $check = $conn->prepare("SELECT id FROM deve_rasel_id WHERE trxid = ?");
    $check->bind_param("s", $trxid);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        echo json_encode(["status"=>"error","message"=>"Duplicate: This TrxID ($trxid) is already recorded."]);
        exit;
    }
    $check->close();

    $stmt = $conn->prepare("INSERT INTO deve_rasel_id (amount, trxid, user_key) VALUES (?, ?, ?)");
    $stmt->bind_param("dss", $amount, $trxid, $user_key);
    if ($stmt->execute()) {
        echo json_encode(["status"=>"ok","message"=>"Transaction Recorded Successfully!","trxid"=>$trxid,"amount"=>$amount]);
    } else {
        echo json_encode(["status"=>"error","message"=>"Database Insert Failed: " . $conn->error]);
    }
    $stmt->close(); $conn->close();
    exit;
}

/* ============== 4) VERIFY (POST) ============== */
if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['reg_data'])) { header("Location: index.php"); exit; }

    $trxid  = strtoupper(trim($_POST['trxid'] ?? ''));
    $method = trim($_POST['method'] ?? '');
    if ($trxid === '' || $method === '') {
        header("Location: index.php?action=payment&error=invalid_trx"); exit;
    }

    $reg_data = $_SESSION['reg_data'];
    $slot_id  = (int)($reg_data['slot_id'] ?? 0);
    if ($slot_id <= 0) { header("Location: index.php"); exit; }

    // স্লটের সঠিক দাম (POST amount বিশ্বাস নয়)
    $slot_res = mysqli_query($conn, "SELECT price FROM slots WHERE id = $slot_id");
    $slot = mysqli_fetch_assoc($slot_res);
    if (!$slot) { header("Location: index.php"); exit; }
    $expected_amount = (float)$slot['price'];

    $conn->begin_transaction();
    try {
        // ট্রানজেকশন লক করে খোঁজা
        $stmt = $conn->prepare("SELECT id FROM deve_rasel_id WHERE trxid = ? AND amount = ? FOR UPDATE");
        $stmt->bind_param("sd", $trxid, $expected_amount);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $conn->rollback();
            header("Location: index.php?action=payment&error=invalid_trx");
            exit;
        }
        $trx_id = (int)$result->fetch_assoc()['id'];
        $stmt->close();

        // স্লট এখনো খালি কিনা
        $stmt = $conn->prepare("SELECT total_slots, booked FROM slots WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $slot_id);
        $stmt->execute();
        $slot_lock = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$slot_lock || $slot_lock['booked'] >= $slot_lock['total_slots']) {
            $conn->rollback();
            header("Location: index.php?action=payment&error=slot_full");
            exit;
        }

        $team = trim($reg_data['team_name'] ?? '');
        $wa   = trim($reg_data['whatsapp'] ?? '');
        $igl  = trim($reg_data['gmail'] ?? '');
        if ($igl === '') $igl = 'N/A';

        $stmt = $conn->prepare("INSERT INTO registrations 
            (slot_id, team_name, igl_name, whatsapp, payment_method, transaction_id, amount, payment_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Success')");
        $stmt->bind_param("isssssd", $slot_id, $team, $igl, $wa, $method, $trxid, $expected_amount);
        if (!$stmt->execute()) throw new Exception("Reg insert failed: " . $stmt->error);
        $stmt->close();

        $stmt = $conn->prepare("UPDATE slots SET booked = booked + 1 WHERE id = ?");
        $stmt->bind_param("i", $slot_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM deve_rasel_id WHERE id = ?");
        $stmt->bind_param("i", $trx_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        $_SESSION['success_info'] = [
            'team'    => $team,
            'trx'     => $trxid,
            'amount'  => $expected_amount,
            'slot_id' => $slot_id,
        ];
        unset($_SESSION['reg_data']);
        header("Location: index.php?action=success");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: index.php?action=payment&error=server");
        exit;
    }
}

/* ============== 5) PAYMENT PAGE ============== */
if ($action === 'payment') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['team_name'])) {
        $_SESSION['reg_data'] = $_POST;
    }
    if (!isset($_SESSION['reg_data'])) { header("Location: index.php"); exit; }

    $reg_data = $_SESSION['reg_data'];
    $slot_id  = (int)($reg_data['slot_id'] ?? 0);
    if ($slot_id <= 0) { header("Location: index.php"); exit; }

    $slot_res  = mysqli_query($conn, "SELECT price, name, group_name, total_slots, booked FROM slots WHERE id = $slot_id");
    $slot_info = mysqli_fetch_assoc($slot_res);
    if (!$slot_info || $slot_info['booked'] >= $slot_info['total_slots']) {
        header("Location: index.php"); exit;
    }
    $payable_amount = $slot_info['price'];

    $settings = [];
    $res = mysqli_query($conn, "SELECT * FROM settings");
    while($row = mysqli_fetch_assoc($res)) { $settings[$row['setting_key']] = $row['setting_value']; }

    $error_map = [
        'invalid_trx'     => "ভুল ট্রানজেকশন আইডি! আবার চেষ্টা করুন।",
        'mismatch_amount' => "অ্যামাউন্ট মিলছে না। সঠিক টাকা পাঠিয়েছেন তো?",
        'slot_full'       => "দুঃখিত, এই স্লট এখন ফুল হয়ে গেছে। অন্য স্লট বেছে নিন।",
        'server'          => "সার্ভার সমস্যা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।",
    ];
    $error_msg = $error_map[$_GET['error'] ?? ''] ?? "";
    ?>
    <!DOCTYPE html>
    <html lang="bn">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment - Hunter Esports</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            body { background-color: #FDFCFB; font-family: 'Segoe UI', Tahoma, sans-serif; }
            .mobile-frame { max-width: 420px; margin: 0 auto; min-height: 100vh; background: #fff; }
            .shake { animation: shake 0.5s ease-in-out; }
            @keyframes shake {
                0%,100% { transform: translateX(0); }
                25% { transform: translateX(-5px); }
                75% { transform: translateX(5px); }
            }
            .bkash-checked:checked + .btn-design { background-color: #e2136e !important; color:#fff !important; border-color:#e2136e !important; }
            .nagad-checked:checked + .btn-design { background-color: #f6921e !important; color:#fff !important; border-color:#f6921e !important; }
            .rocket-checked:checked + .btn-design { background-color: #8C3494 !important; color:#fff !important; border-color:#8C3494 !important; }
        </style>
    </head>
    <body class="bg-gray-100">
        <div class="mobile-frame shadow-2xl flex flex-col overflow-hidden">
            <div class="bg-[#EB4D27] p-8 text-white text-center rounded-b-[50px] shadow-lg">
                <p class="text-xs font-bold opacity-80 uppercase tracking-widest">Payable Amount</p>
                <h1 class="text-5xl font-black mt-2 italic">৳<?php echo htmlspecialchars($payable_amount); ?></h1>
                <p class="text-[10px] mt-3 font-bold bg-white/20 inline-block px-4 py-1 rounded-full uppercase">
                    <?php echo htmlspecialchars($slot_info['name']); ?> - <?php echo htmlspecialchars($slot_info['group_name']); ?>
                </p>
            </div>
            <div class="p-6 mt-4 flex-grow">
                <?php if($error_msg): ?>
                <div class="shake bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-xl">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-red-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                        <p class="ml-3 text-sm font-bold text-red-700"><?php echo htmlspecialchars($error_msg); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <h3 class="text-center text-gray-400 text-[10px] font-black uppercase tracking-[3px] mb-6">Payment Numbers</h3>

                <div class="space-y-3 mb-10">
                    <?php
                    $methods_list = [
                        ['name'=>'bKash',  'key'=>'bkash_number',  'logo'=>'https://i.postimg.cc/SxsrwqvT/b-Kash-Logo.png',  'bg'=>'bg-pink-50',   'text'=>'text-pink-600'],
                        ['name'=>'Nagad',  'key'=>'nagad_number',  'logo'=>'https://i.postimg.cc/02tGGFyY/images.jpg',       'bg'=>'bg-orange-50', 'text'=>'text-orange-600'],
                        ['name'=>'Rocket', 'key'=>'rocket_number', 'logo'=>'https://i.postimg.cc/nzYBXw5b/unnamed.png',      'bg'=>'bg-purple-50', 'text'=>'text-purple-600'],
                    ];
                    foreach($methods_list as $m):
                        if(!empty($settings[$m['key']])): ?>
                        <div class="flex items-center justify-between p-4 <?php echo $m['bg']; ?> rounded-2xl border border-white/50 shadow-sm">
                            <div class="flex items-center gap-3">
                                <img src="<?php echo $m['logo']; ?>" class="h-6 w-6 rounded-lg object-cover">
                                <span class="font-black <?php echo $m['text']; ?> text-xs uppercase"><?php echo $m['name']; ?></span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="font-black text-gray-800 text-sm"><?php echo htmlspecialchars($settings[$m['key']]); ?></span>
                                <button type="button" onclick="copyNumber('<?php echo htmlspecialchars($settings[$m['key']]); ?>')" class="bg-white text-[9px] font-black px-3 py-1.5 rounded-lg shadow-sm active:scale-90 transition-all uppercase">Copy</button>
                            </div>
                        </div>
                    <?php endif; endforeach; ?>
                </div>

                <form action="index.php?action=verify" method="POST" class="space-y-5">
                    <div class="space-y-4">
                        <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">Select Method & Enter ID</label>
                        <div class="grid grid-cols-3 gap-2">
                            <label class="cursor-pointer">
                                <input type="radio" name="method" value="bKash" class="peer hidden bkash-checked" required>
                                <div class="btn-design py-3 border-2 border-gray-100 rounded-2xl text-center text-[10px] font-black text-gray-400 transition-all uppercase">bKash</div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="method" value="Nagad" class="peer hidden nagad-checked" required>
                                <div class="btn-design py-3 border-2 border-gray-100 rounded-2xl text-center text-[10px] font-black text-gray-400 transition-all uppercase">Nagad</div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="method" value="Rocket" class="peer hidden rocket-checked" required>
                                <div class="btn-design py-3 border-2 border-gray-100 rounded-2xl text-center text-[10px] font-black text-gray-400 transition-all uppercase">Rocket</div>
                            </label>
                        </div>
                        <input type="text" name="trxid" placeholder="Transaction ID (TrxID)" class="w-full p-5 bg-gray-50 border-2 border-gray-100 rounded-2xl focus:outline-none focus:border-[#EB4D27] font-bold uppercase placeholder:capitalize transition-all" required>
                    </div>
                    <button type="submit" class="w-full bg-[#EB4D27] text-white py-5 rounded-[25px] font-black shadow-xl shadow-orange-200 hover:bg-[#d13d1a] transition-all uppercase tracking-[2px] mt-4">
                        Verify Transaction
                    </button>
                </form>
            </div>
            <div class="p-6 text-center">
                <p class="text-[9px] text-gray-300 font-bold uppercase tracking-widest">Hunter Esports Automatic Verification</p>
            </div>
        </div>
        <script>
            function copyNumber(num) {
                navigator.clipboard.writeText(num).then(() => alert("নম্বর কপি হয়েছে!"));
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

/* ============== 6) SUCCESS PAGE ============== */
if ($action === 'success') {
    if (!isset($_SESSION['success_info'])) { header("Location: index.php"); exit; }
    $info = $_SESSION['success_info'];
    $slot_id = (int)$info['slot_id'];

    $stmt = $conn->prepare("SELECT group_link FROM slots WHERE id = ?");
    $stmt->bind_param("i", $slot_id);
    $stmt->execute();
    $slot_row = $stmt->get_result()->fetch_assoc();
    $whatsapp_link = $slot_row['group_link'] ?? '#';
    $stmt->close();
    ?>
    <!DOCTYPE html>
    <html lang="bn">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Registration Success</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
        <div class="max-w-sm w-full bg-white rounded-[40px] p-8 text-center shadow-xl">
            <div class="w-16 h-16 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-4 text-white text-3xl font-bold">✓</div>
            <h1 class="text-2xl font-black mb-6">রেজিস্ট্রেশন সফল!</h1>
            <div class="bg-gray-50 rounded-2xl p-4 text-left space-y-2 mb-6">
                <p class="text-xs text-gray-500 font-bold uppercase">Team: <span class="text-black"><?php echo htmlspecialchars($info['team']); ?></span></p>
                <p class="text-xs text-gray-500 font-bold uppercase">TrxID: <span class="text-orange-600"><?php echo htmlspecialchars($info['trx']); ?></span></p>
                <p class="text-xs text-gray-500 font-bold uppercase">Amount: <span class="text-green-600">৳<?php echo htmlspecialchars($info['amount']); ?></span></p>
            </div>
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3">আপনার কনফার্মেশন গ্রুপে জয়েন করুন</p>
            <a href="<?php echo htmlspecialchars($whatsapp_link); ?>" class="block w-full bg-[#EB4D27] text-white py-4 rounded-2xl font-bold mb-4 shadow-lg active:scale-95 transition-all">JOIN CG</a>
            <a href="index.php" class="text-xs font-bold text-gray-400 uppercase underline">হোমে ফিরে যান</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* ============== 7) DEFAULT: REGISTRATION PAGE ============== */
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hunter Esports - Registration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #FDFCFB; font-family: 'Segoe UI', Tahoma, sans-serif; }
        .bg-custom-orange { background-color: #EB4D27; }
        .text-custom-orange { color: #EB4D27; }
        .slot-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 2px solid #f3f4f6; position: relative; }
        .peer:checked + .slot-card {
            border-color: #EB4D27 !important;
            box-shadow: 0 15px 30px -10px rgba(235, 77, 39, 0.4);
            transform: translateY(-5px) scale(1.02);
        }
        .live-dot { width: 8px; height: 8px; background-color: #ff0000; border-radius: 50%; display: inline-block; margin-right: 4px; animation: pulse 1s infinite; }
        @keyframes pulse { 0%{opacity:1;} 50%{opacity:.3;} 100%{opacity:1;} }
    </style>
</head>
<body class="p-4 md:p-10 flex justify-center items-center min-h-screen">
    <div class="max-w-md w-full bg-white rounded-[40px] shadow-2xl border border-gray-50 overflow-hidden pb-6">
        <div class="p-8 text-center bg-[#FFFBF9]">
            <img src="logo.png" class="h-16 mx-auto mb-4" alt="Logo" onerror="this.style.display='none'">
            <h1 class="text-custom-orange text-2xl font-black uppercase tracking-tight leading-none">HUNTER ESPORTS ORG</h1>
            <p class="text-gray-400 text-[10px] mt-2 font-bold uppercase tracking-widest">Tournament Registration</p>
            <p class="text-gray-500 text-sm mt-1 font-medium">নিচের ফরমটি নির্ভুলভাবে পূরণ করুন</p>
        </div>

        <form action="index.php?action=payment" method="POST" class="px-6 space-y-5 mt-6">
            <div class="space-y-4">
                <div>
                    <label class="text-custom-orange text-[11px] font-black uppercase mb-1 block">Team Name *</label>
                    <input type="text" name="team_name" placeholder="আপনার টিমের নাম দিন" class="w-full p-4 bg-[#F9F9F9] border border-gray-100 rounded-2xl focus:outline-none focus:border-orange-500 font-semibold" required>
                </div>
                <div>
                    <label class="text-custom-orange text-[11px] font-black uppercase mb-1 block">WhatsApp Number *</label>
                    <input type="number" name="whatsapp" placeholder="সচল হোয়াটসঅ্যাপ নাম্বার দিন" class="w-full p-4 bg-[#F9F9F9] border border-gray-100 rounded-2xl focus:outline-none focus:border-orange-500 font-semibold" required>
                </div>
                <div>
                    <label class="text-custom-orange text-[11px] font-black uppercase mb-1 block">Your Gmail (Optional)</label>
                    <input type="email" name="gmail" placeholder="আপনার জিমেইল দিন (না দিলেও হবে)" class="w-full p-4 bg-[#F9F9F9] border border-gray-100 rounded-2xl focus:outline-none focus:border-orange-500 font-semibold">
                </div>
            </div>

            <!-- স্লট এবং মূল্য তালিকা -->
            <div class="mt-10">
                <div class="flex items-center justify-center gap-2 mb-4 bg-orange-50 py-2 rounded-xl">
                    <span class="bg-orange-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-[10px] font-bold">i</span>
                    <span class="font-bold text-[11px] text-gray-700 uppercase">স্লট এবং মূল্য তালিকা</span>
                </div>
                <div class="space-y-2">
                    <?php
                    $slots_query = mysqli_query($conn, "SELECT * FROM slots WHERE status='Active' ORDER BY sort_order ASC");
                    $all_slots = [];
                    while($s = mysqli_fetch_assoc($slots_query)) {
                        $all_slots[] = $s;
                        $rem = $s['total_slots'] - $s['booked'];
                        $is_full = ($rem <= 0);
                    ?>
                    <div class="flex justify-between items-center p-3 bg-white border border-gray-100 rounded-2xl shadow-sm <?php echo $is_full ? 'opacity-60 bg-gray-50' : ''; ?>">
                        <div class="flex flex-col">
                            <div class="flex gap-2 items-center">
                                <span class="font-bold text-[11px] uppercase text-gray-700"><?php echo htmlspecialchars($s['name']); ?> <span class="text-custom-orange"><?php echo htmlspecialchars($s['group_name']); ?></span></span>
                                <?php if(!empty($s['is_live'])){ echo '<span class="flex items-center gap-1"><span class="live-dot"></span><span class="text-[8px] font-bold text-red-600 uppercase">Live</span></span>'; } ?>
                            </div>
                            <p class="text-[9px] text-gray-900 font-black italic"><?php echo htmlspecialchars($s['match_time']); ?></p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-black text-sm text-gray-800">৳<?php echo htmlspecialchars($s['price']); ?></span>
                            <span class="<?php echo $is_full ? 'bg-red-100 text-red-600' : 'bg-green-50 text-green-600'; ?> text-[9px] font-black px-2 py-1 rounded-lg uppercase tracking-tighter">
                                <?php echo $is_full ? 'Sold Out' : $rem . ' Left'; ?>
                            </span>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <?php
            $available_count = 0;
            foreach ($all_slots as $r) {
                if (($r['total_slots'] - $r['booked']) > 0) $available_count++;
            }
            ?>

            <div class="mt-10">
                <?php if ($available_count === 0): ?>
                    <div class="bg-red-50 border border-red-200 rounded-2xl p-5 text-center">
                        <p class="text-red-600 font-black text-sm">দুঃখিত, সব স্লট বর্তমানে ফুল!</p>
                        <p class="text-red-400 text-[10px] mt-1 font-bold">পরবর্তী টুর্নামেন্টের জন্য অপেক্ষা করুন</p>
                    </div>
                <?php else: ?>
                    <div class="flex items-center gap-2 mb-4">
                        <span class="font-black text-sm text-gray-700 uppercase">স্লট নির্বাচন করুন</span>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <?php foreach($all_slots as $row) {
                            $left = $row['total_slots'] - $row['booked'];
                            $is_sold_out = ($left <= 0);
                            $image_filename = basename($row['image']);
                            $final_img = "uploads/" . $image_filename;
                        ?>
                        <label class="relative block h-full group <?php echo $is_sold_out ? 'cursor-not-allowed' : 'cursor-pointer'; ?>">
                            <input type="radio" name="slot_id" value="<?php echo (int)$row['id']; ?>"
                                   class="peer hidden" data-price="<?php echo (int)$row['price']; ?>"
                                   <?php echo $is_sold_out ? 'disabled' : 'required'; ?>
                                   onclick="updateTotal(this)">
                            <div class="slot-card bg-white rounded-3xl overflow-hidden shadow-sm h-full flex flex-col <?php echo $is_sold_out ? 'opacity-60 grayscale-[0.5]' : ''; ?>">
                                <?php if($is_sold_out): ?>
                                <div class="absolute inset-0 z-30 flex items-center justify-center bg-black/10">
                                    <span class="bg-red-600 text-white font-black text-[10px] px-3 py-1 rounded-full shadow-lg uppercase tracking-widest transform -rotate-12">Sold Out</span>
                                </div>
                                <?php endif; ?>
                                <?php if(!empty($row['is_live']) && !$is_sold_out) { ?>
                                    <div class="absolute top-2 left-2 z-10 bg-white/90 px-2 py-1 rounded-full flex items-center gap-1 shadow-sm">
                                        <span class="live-dot"></span>
                                        <span class="text-[8px] font-black text-red-600 uppercase tracking-tighter">Live</span>
                                    </div>
                                <?php } ?>
                                <div class="h-28 bg-gray-50 flex items-center justify-center overflow-hidden relative">
                                    <img src="<?php echo htmlspecialchars($final_img); ?>"
                                         class="w-full h-full object-cover transition-transform duration-500 <?php echo !$is_sold_out ? 'group-hover:scale-110' : ''; ?>"
                                         onerror="this.src='https://via.placeholder.com/300x150?text=Banner+Error'">
                                </div>
                                <div class="p-3 flex-grow flex flex-col justify-between">
                                    <h3 class="text-[10px] font-black leading-tight uppercase mb-1">
                                        <?php echo htmlspecialchars($row['name']); ?> <br><span class="text-custom-orange text-[9px]"><?php echo htmlspecialchars($row['group_name']); ?></span>
                                    </h3>
                                    <p class="text-[9px] text-gray-900 font-black italic mb-2">
                                        <?php echo htmlspecialchars($row['match_time']); ?>
                                    </p>
                                    <div class="flex justify-between items-end border-t border-gray-50 pt-2">
                                        <p class="text-[8px] <?php echo $is_sold_out ? 'text-red-600' : 'text-gray-400'; ?> font-bold uppercase tracking-tighter">
                                            <?php echo $is_sold_out ? 'Sold Out' : $left . ' স্লট বাকি'; ?>
                                        </p>
                                        <p class="text-custom-orange font-black text-xs italic leading-none">৳<?php echo htmlspecialchars($row['price']); ?></p>
                                    </div>
                                </div>
                            </div>
                        </label>
                        <?php } ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="py-6 border-t border-gray-100 mt-6">
                <div class="flex justify-between items-center mb-6">
                    <span class="text-[10px] text-gray-400 font-black uppercase tracking-widest">Total Amount</span>
                    <span class="text-custom-orange text-3xl font-black italic tracking-tighter leading-none">৳ <span id="total_price">0</span></span>
                </div>
                <?php if ($available_count > 0): ?>
                    <button type="submit" class="w-full bg-custom-orange text-white py-5 rounded-3xl font-black shadow-xl shadow-orange-100 hover:scale-[1.02] active:scale-95 transition-all uppercase tracking-widest">
                        পেমেন্ট করুন
                    </button>
                <?php else: ?>
                    <button type="button" disabled class="w-full bg-gray-300 text-white py-5 rounded-3xl font-black cursor-not-allowed uppercase tracking-widest">
                        স্লট ফুল
                    </button>
                <?php endif; ?>

                <div class="flex justify-center gap-6 mt-8">
                    <a href="https://youtube.com/@hunteresportsofficial?si=CiTd_XB7luLkRpXJ" target="_blank" class="w-10 h-10 bg-red-50 rounded-full flex items-center justify-center transition-transform hover:scale-110 shadow-sm">
                        <svg class="w-5 h-5 text-red-600 fill-current" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                    </a>
                    <a href="https://www.facebook.com/share/181DGuFJ4a/" target="_blank" class="w-10 h-10 bg-blue-50 rounded-full flex items-center justify-center transition-transform hover:scale-110 shadow-sm">
                        <svg class="w-5 h-5 text-blue-600 fill-current" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </a>
                    <a href="https://wa.me/message/FMUDODJNXIZVA1" target="_blank" class="w-10 h-10 bg-green-50 rounded-full flex items-center justify-center transition-transform hover:scale-110 shadow-sm">
                        <svg class="w-5 h-5 text-green-600 fill-current" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    </a>
                </div>
            </div>
        </form>
        <div class="text-center mt-4 pb-4">
            <p class="text-[9px] text-gray-300 font-black uppercase tracking-[2px]">Hunter Esports Org Secure System</p>
        </div>
    </div>

    <script>
        function updateTotal(radio) {
            const price = radio.getAttribute('data-price');
            document.getElementById('total_price').innerText = price;
        }
    </script>
</body>
</html>
