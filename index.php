<?php
// Enable error reporting for debugging

session_start();

// Set Indian Standard Time (IST)
date_default_timezone_set('Asia/Kolkata');

// ==========================================
// SQLITE DATABASE INITIALIZATION & MIGRATION
// ==========================================
$dbFile = __DIR__ . '/diwali_database.sqlite';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("
        CREATE TABLE IF NOT EXISTS analytics (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            views INTEGER DEFAULT 0,
            shares INTEGER DEFAULT 0,
            creations INTEGER DEFAULT 0,
            revenue INTEGER DEFAULT 0,
            pro_wishes INTEGER DEFAULT 0
        );
        INSERT OR IGNORE INTO analytics (id, views, shares, creations, revenue, pro_wishes) VALUES (1, 0, 0, 0, 0, 0);

        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password TEXT
        );

        CREATE TABLE IF NOT EXISTS wishes (
            id TEXT PRIMARY KEY,
            user_id INTEGER,
            to_name TEXT,
            from_name TEXT,
            message TEXT,
            bg_media TEXT,
            media_type TEXT,
            inline_image TEXT,
            inline_img_width TEXT DEFAULT '50%',
            inline_img_align TEXT DEFAULT 'center',
            audio_type TEXT,
            voice_note TEXT,
            custom_audio TEXT,
            is_pro INTEGER,
            is_paid INTEGER DEFAULT 0,
            created_at TEXT,
            views INTEGER DEFAULT 0,
            FOREIGN KEY(user_id) REFERENCES users(id)
        );
    ");

    $cols = $db->query("PRAGMA table_info(wishes)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('bg_media', $colNames)) { $db->exec("ALTER TABLE wishes ADD COLUMN bg_media TEXT;"); }
    if (!in_array('media_type', $colNames)) { $db->exec("ALTER TABLE wishes ADD COLUMN media_type TEXT;"); }
    if (!in_array('inline_image', $colNames)) { $db->exec("ALTER TABLE wishes ADD COLUMN inline_image TEXT;"); }
    if (!in_array('inline_img_width', $colNames)) { $db->exec("ALTER TABLE wishes ADD COLUMN inline_img_width TEXT DEFAULT '50%';"); }
    if (!in_array('inline_img_align', $colNames)) { $db->exec("ALTER TABLE wishes ADD COLUMN inline_img_align TEXT DEFAULT 'center';"); }

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
    exit;
}

// ==========================================
// AJAX BACKEND ENDPOINTS & AUTHENTICATION
// ==========================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_GET['action'] === 'register_or_login') {
            $input = json_decode(file_get_contents('php://input'), true);
            $username = trim($input['username'] ?? '');
            $password = trim($input['password'] ?? '');

            if (empty($username)) {
                echo json_encode(['status' => 'error', 'message' => 'Username is required.']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                if (!empty($user['password']) && !password_verify($password, $user['password'])) {
                    echo json_encode(['status' => 'error', 'message' => 'Incorrect password for this username.']);
                    exit;
                }
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                echo json_encode(['status' => 'success', 'user_id' => $user['id']]);
                exit;
            } else {
                $hashedPwd = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : '';
                $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                $stmt->execute([$username, $hashedPwd]);
                $userId = $db->lastInsertId();

                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                echo json_encode(['status' => 'success', 'user_id' => $userId]);
                exit;
            }
        }

        if ($_GET['action'] === 'logout') {
            session_destroy();
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($_GET['action'] === 'track_view') {
            $db->exec("UPDATE analytics SET views = views + 1 WHERE id = 1");
            $stmt = $db->query("SELECT views FROM analytics WHERE id = 1");
            echo json_encode(['status' => 'success', 'views' => $stmt->fetchColumn()]);
            exit;
        }
        
        if ($_GET['action'] === 'track_share') {
            $db->exec("UPDATE analytics SET shares = shares + 1 WHERE id = 1");
            $stmt = $db->query("SELECT shares FROM analytics WHERE id = 1");
            echo json_encode(['status' => 'success', 'shares' => $stmt->fetchColumn()]);
            exit;
        }

        if ($_GET['action'] === 'save_wish') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (strlen(json_encode($input)) > 25000000) {
                echo json_encode(['status' => 'error', 'message' => 'Payload exceeds 25MB limit.']);
                exit;
            }

            $wishId = 'wish_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
            $isPro = isset($input['is_pro']) && $input['is_pro'] ? 1 : 0;
            $isPaid = $isPro ? 0 : 1; 
            $userId = $_SESSION['user_id'] ?? null;
            
            $defaultBg = "bg-diwali.gif";
            $bgMedia = !empty($input['bg_media']) ? $input['bg_media'] : $defaultBg;
            $mediaType = !empty($input['media_type']) ? $input['media_type'] : 'image';
            $defaultInline = "inline-diya.webp";
            $inlineImage = !empty($input['inline_image']) ? $input['inline_image'] : $defaultInline;

            $stmt = $db->prepare("INSERT INTO wishes (id, user_id, to_name, from_name, message, bg_media, media_type, inline_image, inline_img_width, inline_img_align, audio_type, voice_note, custom_audio, is_pro, is_paid, created_at, views) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'), 0)");
            $stmt->execute([
                $wishId,
                $userId,
                $input['to'] ?? 'Friend',
                $input['from'] ?? 'Someone Special',
                $input['message'] ?? 'May this Diwali bring joy!',
                $bgMedia,
                $mediaType,
                $inlineImage,
                $input['inline_img_width'] ?? '50%',
                $input['inline_img_align'] ?? 'center',
                $input['audio_type'] ?? 'classic',
                $input['voice_note'] ?? '',
                $input['custom_audio'] ?? '',
                $isPro,
                $isPaid
            ]);

            $db->exec("UPDATE analytics SET creations = creations + 1" . ($isPro ? ", pro_wishes = pro_wishes + 1" : "") . " WHERE id = 1");

            echo json_encode(['status' => 'success', 'wish_id' => $wishId, 'is_paid' => $isPaid]);
            exit;
        }

        if ($_GET['action'] === 'process_payment') {
            $input = json_decode(file_get_contents('php://input'), true);
            $wishId = $input['wish_id'] ?? '';
            
            if ($wishId) {
                usleep(800000); 
                $stmt = $db->prepare("UPDATE wishes SET is_paid = 1 WHERE id = ?");
                $stmt->execute([$wishId]);
                $db->exec("UPDATE analytics SET revenue = revenue + 99 WHERE id = 1");
                
                echo json_encode(['status' => 'success']);
                exit;
            }
            echo json_encode(['status' => 'error', 'message' => 'Missing wish ID']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ==========================================
// PAGE ROUTING & DATA FETCHING
// ==========================================
$wishId = isset($_GET['id']) ? $_GET['id'] : null;
$currentWish = null;

if ($wishId) {
    try {
        $stmt = $db->prepare("SELECT * FROM wishes WHERE id = ?");
        $stmt->execute([$wishId]);
        $currentWish = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($currentWish && $currentWish['is_paid'] == 1) {
            $db->prepare("UPDATE wishes SET views = views + 1 WHERE id = ?")->execute([$wishId]);
            $db->exec("UPDATE analytics SET views = views + 1 WHERE id = 1");
        }
    } catch (Exception $e) {
        $currentWish = null;
    }
}

$adminStats = $db->query("SELECT * FROM analytics WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$isAdmin = isset($_GET['admin']) && $_GET['admin'] === 'secret123Abhishek';

$currentUserId = $_SESSION['user_id'] ?? null;
$userWishes = [];
if ($currentUserId) {
    $stmt = $db->prepare("SELECT * FROM wishes WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$currentUserId]);
    $userWishes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$allWishes = $isAdmin ? $db->query("SELECT * FROM wishes ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) : [];

$ogTitle = ($currentWish && $currentWish['is_paid'] == 1) ? "✨ {$currentWish['from_name']} sent a magical Diwali wish to {$currentWish['to_name']}!" : "✨ Diwali 2026 - Send Magical Interactive Wishes";
$ogDesc = ($currentWish && $currentWish['is_paid'] == 1) ? "\"{$currentWish['message']}\" - Tap to open your interactive greeting card!" : "Create personalized, cinematic, interactive Diwali greeting cards with custom audio and live previews.";
$ogImage = ($currentWish && !empty($currentWish['bg_media']) && $currentWish['media_type'] !== 'video') ? $currentWish['bg_media'] : "inline-diya.webp";
$ogImage = "inline-diya.webp";
$currentUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($ogTitle); ?></title>
    
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($currentUrl); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($ogTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($ogDesc); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/' . ltrim($ogImage, '/')); ?>">


    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-cinzel { font-family: 'Cinzel', serif; }
        .glass-card { background: rgba(26, 11, 46, 0.82); backdrop-filter: blur(20px); border: 1px solid rgba(255, 215, 0, 0.25); transition: transform 0.2s cubic-bezier(0.25, 1, 0.5, 1), box-shadow 0.2s ease; }
        .glow-gold { box-shadow: 0 0 45px -5px rgba(255, 215, 0, 0.3); }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: #FFD700; border-radius: 4px; }
        
        .chingari-spark {
            position: fixed;
            pointer-events: none;
            width: 12px;
            height: 12px;
            background: radial-gradient(circle, #FFF 0%, #FFD700 50%, rgba(255,140,0,0) 100%);
            border-radius: 50%;
            z-index: 9999;
            animation: sparkFade 0.8s cubic-bezier(0.1, 0.8, 0.3, 1) forwards;
            box-shadow: 0 0 10px #FFD700, 0 0 20px #FF8C00;
        }
        @keyframes sparkFade {
            0% { transform: scale(1.4) translate(0, 0); opacity: 1; }
            100% { transform: scale(0.1) translate(var(--tx), var(--ty)); opacity: 0; }
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col justify-between relative selection:bg-amber-500 selection:text-white overflow-x-hidden pb-16" onclick="playTouchSfx(); triggerFirstInteractionAudio();" onmousemove="handleGlobalMagneticMotion(event)" ontouchmove="handleGlobalTouchMotion(event)">

    <!-- Ambient Background Glows -->
    <div class="absolute inset-0 pointer-events-none overflow-hidden z-0">
        <div class="absolute top-1/4 left-1/2 -translate-x-1/2 w-[500px] h-[500px] bg-amber-500/10 rounded-full blur-[140px]"></div>
    </div>

    <canvas id="fireworks-canvas" class="fixed inset-0 pointer-events-none z-10"></canvas>

    <!-- Auto-loaded Background Media -->
    <div class="fixed inset-0 pointer-events-none z-0 overflow-hidden">
        <?php 
        $activeBg = ($currentWish && !empty($currentWish['bg_media'])) ? $currentWish['bg_media'] : "bg-diwali.gif";
        $activeMediaType = ($currentWish && !empty($currentWish['media_type'])) ? $currentWish['media_type'] : 'image';
        ?>
        <?php if ($activeMediaType === 'video'): ?>
            <video autoplay muted loop playsinline class="w-full h-full object-cover opacity-35 filter brightness-75">
                <source src="<?php echo htmlspecialchars($activeBg); ?>">
            </video>
        <?php else: ?>
            <div class="w-full h-full bg-cover bg-center opacity-30 filter brightness-75" style="background-image: url('<?php echo htmlspecialchars($activeBg); ?>');"></div>
        <?php endif; ?>
    </div>

    <!-- Auto-loaded Background Music Element -->
    <audio id="bg-music" loop>
        <?php if ($currentWish && !empty($currentWish['custom_audio'])): ?>
            <source src="<?php echo htmlspecialchars($currentWish['custom_audio']); ?>">
        <?php else: ?>
            <source src="bgm-diwali.mp3">
        <?php endif; ?>
    </audio>

    <!-- Floating Audio Pill -->
    <div class="fixed bottom-4 right-4 z-40 glass-card px-3 py-2 rounded-full shadow-2xl flex items-center space-x-2.5 glow-gold border border-amber-500/30 magnetic-element">
        <button onclick="toggleAudio(); event.stopPropagation();" class="w-7 h-7 rounded-full bg-amber-500 text-slate-950 flex items-center justify-center text-[10px] shadow-md hover:scale-105 transition">
            <i id="sound-icon" class="fa-solid fa-play ml-0.5"></i>
        </button>
        <div class="hidden sm:flex flex-col">
            <span class="text-[9px] text-amber-300 font-bold uppercase tracking-wider" data-hi="संगीत" data-en="BGM">BGM</span>
        </div>
        <input type="range" id="volume-slider" min="0" max="1" step="0.05" value="0.5" oninput="adjustVolume(this.value); event.stopPropagation();" class="w-16 accent-amber-400 cursor-pointer h-1 bg-white/20 rounded-lg">
    </div>

    <!-- Top Navigation Bar -->
    <header class="relative z-30 w-full px-4 sm:px-6 py-4 flex justify-between items-center max-w-xl mx-auto">
        <a href="./" class="flex items-center space-x-2 group magnetic-element">
            <span class="text-2xl animate-bounce">🪔</span>
            <span class="font-cinzel font-bold text-sm sm:text-lg tracking-wider text-amber-400 group-hover:text-amber-300 transition">DIWALI 2026</span>
        </a>
        <div class="flex items-center space-x-2">
            <!-- Language Switcher Button (Updates URL & Share link) -->
            <button onclick="toggleLanguage(); event.stopPropagation();" class="glass-card px-3 py-1.5 rounded-full text-xs font-bold text-amber-400 hover:bg-white/10 transition magnetic-element flex items-center space-x-1">
                <i class="fa-solid fa-globe"></i>
                <span id="lang-btn-text">हिंदी</span>
            </button>

            <?php if ($currentUserId): ?>
                <button onclick="openUserDashboard(); event.stopPropagation();" class="glass-card px-3 py-1.5 rounded-full text-xs font-medium text-amber-300 hover:bg-white/10 transition flex items-center space-x-1 magnetic-element">
                    <i class="fa-solid fa-user"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                </button>
            <?php else: ?>
                <button onclick="openAuthModal(); event.stopPropagation();" class="glass-card px-3 py-1.5 rounded-full text-xs font-medium text-amber-300 hover:bg-white/10 transition flex items-center space-x-1 magnetic-element">
                    <i class="fa-solid fa-user-lock"></i>
                    <span data-hi="लॉगिन" data-en="Login">Login</span>
                </button>
            <?php endif; ?>
            <?php if ($currentWish): ?>
            <a href="./" class="glass-card px-3 py-1.5 rounded-full text-xs font-medium text-amber-300 hover:bg-white/10 transition magnetic-element">
                <i class="fa-solid fa-plus mr-1"></i> <span data-hi="नया" data-en="New">New</span>
            </a>
            <?php endif; ?>
        </div>
    </header>

    <main class="relative z-20 flex-grow flex items-center justify-center px-4 py-6">

        <?php if ($isAdmin): ?>
            <!-- SUPER ADMIN DASHBOARD -->
            <div class="w-full max-w-xl glass-card rounded-3xl p-6 glow-gold magnetic-element">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="font-cinzel text-xl font-bold text-amber-400"><i class="fa-solid fa-shield-halved mr-2"></i> Super Admin Dashboard</h2>
                    <span class="bg-emerald-500/20 text-emerald-400 text-xs px-2.5 py-1 rounded-full font-semibold">Master Access</span>
                </div>
                
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                    <div class="bg-black/30 p-3 rounded-2xl border border-white/5 text-center">
                        <div class="text-xl font-bold text-amber-400"><?php echo $adminStats['views']; ?></div>
                        <div class="text-[11px] text-slate-400 mt-1">Total Views</div>
                    </div>
                    <div class="bg-black/30 p-3 rounded-2xl border border-white/5 text-center">
                        <div class="text-xl font-bold text-amber-400"><?php echo $adminStats['shares']; ?></div>
                        <div class="text-[11px] text-slate-400 mt-1">Total Shares</div>
                    </div>
                    <div class="bg-black/30 p-3 rounded-2xl border border-white/5 text-center">
                        <div class="text-xl font-bold text-purple-400"><?php echo $adminStats['creations']; ?></div>
                        <div class="text-[11px] text-slate-400 mt-1">Wishes Made</div>
                    </div>
                    <div class="bg-black/30 p-3 rounded-2xl border border-white/5 text-center">
                        <div class="text-xl font-bold text-emerald-400">₹<?php echo $adminStats['revenue']; ?></div>
                        <div class="text-[11px] text-slate-400 mt-1">Pro Revenue</div>
                    </div>
                </div>

                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-400 mb-3">All System Wishes Log</h3>
                <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                    <?php if (empty($allWishes)): ?>
                        <div class="text-xs text-slate-500 text-center py-4">No wishes logged yet.</div>
                    <?php else: ?>
                        <?php foreach ($allWishes as $w): ?>
                            <div class="bg-black/40 p-3 rounded-xl border border-white/5 flex justify-between items-center text-xs">
                                <div>
                                    <span class="font-bold text-white"><?php echo htmlspecialchars($w['from_name']); ?></span> 
                                    <span class="text-slate-400">➔</span> 
                                    <span class="font-bold text-amber-400"><?php echo htmlspecialchars($w['to_name']); ?></span>
                                    <div class="text-[10px] text-slate-500"><?php echo $w['created_at']; ?> (IST) • <?php echo $w['is_pro'] ? '<span class="text-amber-300 font-bold">PRO</span>' : 'FREE'; ?></div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <a href="./?id=<?php echo $w['id']; ?>" target="_blank" class="px-2.5 py-1 bg-amber-500 text-slate-950 font-bold rounded-lg text-[10px]">Open</a>
                                    <span class="text-amber-300 font-bold"><?php echo $w['views']; ?> views</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="mt-6">
                    <a href="./" class="w-full block text-center py-3 bg-amber-500 text-slate-950 font-bold rounded-2xl hover:bg-amber-400 transition text-sm">
                        Back to App
                    </a>
                </div>
            </div>

        <?php elseif ($currentWish): ?>
            <!-- RECEIVER VIEWING WISH -->
            <?php if ($currentWish['is_paid'] == 0): ?>
                <div class="w-full max-w-md glass-card rounded-3xl p-8 glow-gold text-center space-y-4 magnetic-element">
                    <div class="inline-block p-4 rounded-full bg-red-500/20 text-red-400 text-3xl">
                        <i class="fa-solid fa-lock"></i>
                    </div>
                    <h2 class="font-cinzel text-2xl font-bold text-white" data-hi="प्रो शुभकामना लॉक है" data-en="Pro Wish Locked">Pro Wish Locked</h2>
                    <p class="text-xs text-slate-300" data-hi="प्रेषक ने इस ग्रीटिंग के लिए प्रो एक्टिवेशन पास पूरा नहीं किया है। कृपया उनसे लिंक अनलॉक करने का अनुरोध करें!" data-en="The sender has not yet completed the Pro activation pass for this greeting. Please ask them to unlock the link!">The sender has not yet completed the Pro activation pass for this greeting. Please ask them to unlock the link!</p>
                    <a href="./" class="w-full block py-3 bg-amber-500 text-slate-950 font-bold rounded-xl text-xs hover:bg-amber-400 transition" data-hi="अपनी खुद की शुभकामना बनाएं ✨" data-en="Create Your Own Wish ✨">
                        Create Your Own Wish ✨
                    </a>
                </div>
            <?php else: ?>
                <div class="w-full max-w-md glass-card rounded-3xl p-8 glow-gold text-center relative overflow-hidden space-y-5 magnetic-element">
                    <div class="inline-block p-3.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-3xl animate-pulse">🪔</div>
                    <div>
                        <h3 class="text-[10px] uppercase tracking-widest text-amber-400 font-semibold mb-1" data-hi="विशेष दीपावली शुभकामना" data-en="A Special Diwali Wish For">A Special Diwali Wish For</h3>
                        <h1 class="font-cinzel text-3xl font-extrabold text-white drop-shadow-md"><?php echo htmlspecialchars($currentWish['to_name']); ?></h1>
                    </div>
                    
                    <div class="bg-black/40 rounded-2xl p-5 border border-white/10 text-slate-200 text-sm leading-relaxed relative space-y-4 text-left">
                        <?php 
                            $inlineImgSrc = !empty($currentWish['inline_image']) ? $currentWish['inline_image'] : 'inline-diya.webp';
                            $imgWidth = $currentWish['inline_img_width'] ?? '50%';
                            $imgAlign = $currentWish['inline_img_align'] ?? 'center';
                            $alignClass = 'mx-auto';
                            if ($imgAlign === 'left') $alignClass = 'mr-auto';
                            if ($imgAlign === 'right') $alignClass = 'ml-auto';
                        ?>
                        <div class="overflow-hidden shadow-lg <?php echo $alignClass; ?>" style="width: <?php echo $imgWidth; ?>;">
                            <img src="<?php echo htmlspecialchars($inlineImgSrc); ?>" alt="Greeting Memory" class="w-full h-40 object-cover">
                        </div>
                        
                        <p class="italic text-slate-100 text-center">"<?php echo nl2br(htmlspecialchars($currentWish['message'])); ?>"</p>

                        <?php if (!empty($currentWish['voice_note'])): ?>
                            <div class="pt-2 border-t border-white/10">
                                <div class="text-[11px] text-amber-300 font-semibold mb-1.5 flex items-center">
                                    <i class="fa-solid fa-microphone text-amber-400 mr-1.5 animate-pulse"></i> 
                                    <span data-hi="प्रेषक का वॉइस संदेश" data-en="Sender's Voice Message">Sender's Voice Message</span>
                                </div>
                                <audio id="receiver-voice" controls onplay="fadeMusicDown()" onpause="fadeMusicUp()" onended="fadeMusicUp()" class="w-full h-8">
                                    <source src="<?php echo $currentWish['voice_note']; ?>" type="audio/webm">
                                    Your browser does not support the audio element.
                                </audio>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="text-sm text-slate-400">
                        <span data-hi="प्यार सहित:" data-en="With warmth from">With warmth from</span> <span class="text-amber-400 font-bold text-base"><?php echo htmlspecialchars($currentWish['from_name']); ?></span> ✨
                    </div>

                    <div class="space-y-3 pt-2">
                        <button onclick="shareOnWhatsApp()" class="w-full py-4 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-base rounded-2xl shadow-xl flex items-center justify-center space-x-2.5 transition transform active:scale-95 magnetic-element">
                            <i class="fa-brands fa-whatsapp text-2xl"></i>
                            <span data-hi="व्हाट्सएप पर शेयर करें" data-en="Share on WhatsApp">Share on WhatsApp</span>
                        </button>
                        <a href="./" class="w-full block py-3 glass-card hover:bg-white/10 text-amber-300 font-semibold rounded-2xl text-xs transition" data-hi="✨ अपनी खुद की शुभकामना बनाएं" data-en="✨ Create Your Own Wish">
                            ✨ Create Your Own Wish
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- HOME / CREATOR STUDIO -->
            <div class="w-full max-w-lg space-y-6">
                
                <!-- EXACT RECEIVER PREVIEW CONTAINER -->
                <div class="glass-card rounded-3xl p-6 glow-gold text-center relative overflow-hidden transition-all duration-300 space-y-4 magnetic-element">
                    <div class="absolute top-3 right-3 bg-amber-500/20 text-amber-300 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border border-amber-500/30 z-10">
                        <i class="fa-solid fa-eye mr-1"></i> <span data-hi="लाइव प्रीव्यू" data-en="Live Preview">Live Preview</span>
                    </div>
                    
                    <div class="inline-block p-3 rounded-full bg-amber-500/10 border border-amber-500/30 text-2xl animate-pulse">🪔</div>
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-amber-400 font-semibold mb-0.5" data-hi="विशेष दीपावली शुभकामना" data-en="A Special Diwali Wish For">A Special Diwali Wish For</div>
                        <h2 id="prev-to" class="font-cinzel text-2xl font-extrabold text-white">Recipient Name</h2>
                    </div>
                    
                    <div class="bg-black/40 rounded-2xl p-4 border border-white/10 text-slate-200 text-xs leading-relaxed space-y-3 text-left">
                        <div id="prev-inline-container" class="overflow-hidden mx-auto" style="width: 50%;">
                            <img id="prev-inline-img" src="inline-diya.webp" alt="Inline Preview" class="w-full h-32 object-cover">
                        </div>
                        <p id="prev-msg" class="italic text-center text-slate-100">"May the festival of lights illuminate your life with boundless joy, prosperity, and success!"</p>

                        <div id="prev-voice-container" class="hidden pt-2 border-t border-white/10">
                            <div class="text-[10px] text-amber-300 font-semibold mb-1"><i class="fa-solid fa-microphone mr-1"></i> <span data-hi="वॉइस संदेश जुड़ा है" data-en="Voice Message Attached">Voice Message Attached</span></div>
                            <audio id="prev-audio-playback" controls class="w-full h-7"></audio>
                        </div>
                    </div>

                    <div class="text-xs text-slate-400">
                        <span data-hi="प्यार सहित:" data-en="With warmth from">With warmth from</span> <span id="prev-from" class="text-amber-400 font-bold">Your Name</span> ✨
                    </div>
                </div>

                <div class="glass-card rounded-3xl p-6 sm:p-8 glow-gold relative magnetic-element">
                    <div class="text-center mb-5">
                        <h1 class="font-cinzel text-xl sm:text-2xl font-bold text-amber-400 mb-1" data-hi="दीपावली शुभकामना क्रिएटर" data-en="Diwali Wish Creator">Diwali Wish Creator</h1>
                        <p class="text-xs text-slate-300" data-hi="डिफ़ॉल्ट मीडिया (inline-diya.webp, bgm-diwali.mp3, bg-diwali.gif) लोडेड!" data-en="Default media preloaded successfully!">Default media preloaded successfully!</p>
                    </div>

                    <div class="grid grid-cols-2 p-1.5 bg-black/40 rounded-2xl border border-white/10 mb-5">
                        <button onclick="switchTier('free')" id="tab-free" class="py-2 text-xs font-bold rounded-xl bg-amber-500 text-slate-950 transition shadow-md flex items-center justify-center space-x-1">
                            <i class="fa-solid fa-gift mr-1"></i> <span data-hi="फ्री" data-en="Free">Free</span>
                        </button>
                        <button onclick="switchTier('pro')" id="tab-pro" class="py-2 text-xs font-bold rounded-xl text-slate-400 hover:text-white transition flex items-center justify-center space-x-1">
                            <i class="fa-solid fa-crown text-amber-400"></i>
                            <span data-hi="प्रो ✨" data-en="Pro ✨">Pro ✨</span>
                        </button>
                    </div>

                    <!-- FREE FORM -->
                    <form id="form-free" onsubmit="submitWish(event, false)" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-amber-400 mb-1.5" data-hi="प्राप्तकर्ता का नाम" data-en="Recipient Name">Recipient Name</label>
                            <input type="text" id="free-to" required placeholder="e.g. Mom & Dad" oninput="autoSaveDraft(); updatePreview();" class="w-full px-4 py-3 bg-black/40 border border-white/10 rounded-2xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-amber-400">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-amber-400 mb-1.5" data-hi="आपका नाम" data-en="Your Name">Your Name</label>
                            <input type="text" id="free-from" required placeholder="e.g. Rohan" oninput="autoSaveDraft(); updatePreview();" class="w-full px-4 py-3 bg-black/40 border border-white/10 rounded-2xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-amber-400">
                        </div>
                        <button type="submit" class="w-full py-4 bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950 font-bold rounded-2xl shadow-xl hover:opacity-95 transition text-sm mt-2 magnetic-element">
                            <i class="fa-solid fa-wand-magic-sparkles mr-2"></i> <span data-hi="फ्री विश लिंक बनाएं" data-en="Generate Free Wish Link">Generate Free Wish Link</span>
                        </button>
                    </form>

                    <!-- PRO MODE FORM -->
                    <div id="form-pro" class="hidden space-y-4">
                        <div class="flex space-x-2 border-b border-white/10 pb-3">
                            <button type="button" onclick="switchProTab('details')" id="pro-tab-details" class="text-xs font-bold text-amber-400 pb-1 border-b-2 border-amber-400 transition" data-hi="1. विवरण और मीडिया" data-en="1. Details & Media">1. Details & Media</button>
                            <button type="button" onclick="validateAndSwitchToMedia()" id="pro-tab-media" class="text-xs font-bold text-slate-400 pb-1 transition" data-hi="2. आवाज़ और ऑडियो" data-en="2. Voice & Audio">2. Voice & Audio</button>
                        </div>

                        <!-- Tab 1: Details & Media -->
                        <div id="pro-section-details" class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase text-amber-400 mb-1" data-hi="प्राप्तकर्ता" data-en="Recipient">Recipient</label>
                                    <input type="text" id="pro-to" placeholder="e.g. Priya" oninput="autoSaveDraft(); updatePreview();" class="w-full px-3 py-2.5 bg-black/40 border border-white/10 rounded-xl text-white text-xs">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase text-amber-400 mb-1" data-hi="प्रेषक" data-en="Sender">Sender</label>
                                    <input type="text" id="pro-from" placeholder="e.g. Karan" oninput="autoSaveDraft(); updatePreview();" class="w-full px-3 py-2.5 bg-black/40 border border-white/10 rounded-xl text-white text-xs">
                                </div>
                            </div>
                            
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <label class="text-[11px] font-semibold uppercase text-amber-400" data-hi="कस्टम दीपावली संदेश" data-en="Custom Diwali Message">Custom Diwali Message</label>
                                    <div class="flex space-x-1">
                                        <button type="button" onclick="applyPresetMessage('family')" class="px-2 py-0.5 bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 rounded text-[9px] font-bold">✨ Family</button>
                                        <button type="button" onclick="applyPresetMessage('love')" class="px-2 py-0.5 bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 rounded text-[9px] font-bold">💖 Love</button>
                                        <button type="button" onclick="applyPresetMessage('success')" class="px-2 py-0.5 bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 rounded text-[9px] font-bold">🚀 Success</button>
                                    </div>
                                </div>
                                <textarea id="pro-msg" rows="3" oninput="autoSaveDraft(); updatePreview();" class="w-full px-3 py-2.5 bg-black/40 border border-white/10 rounded-xl text-white text-xs">May the divine light of Diwali fill your home with peace, boundless joy, prosperity, and endless success. Wishing you a sparkling festival!</textarea>
                            </div>
                            
                            <!-- Inline Image Section (Borderless) -->
                            <div class="bg-black/30 p-3 rounded-2xl border border-white/10 space-y-2.5">
                                <label class="block text-[11px] font-semibold uppercase text-amber-400" data-hi="इमेज (डिफ़ॉल्ट: inline-diya.webp)" data-en="Inline Photo (Default: inline-diya.webp)">Inline Photo (Default: inline-diya.webp)</label>
                                <div class="flex space-x-2">
                                    <input type="text" id="pro-inline-img" value="inline-diya.webp" placeholder="inline-diya.webp" oninput="autoSaveDraft(); updatePreview();" class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-xs">
                                    <input type="file" id="inline-file-input" accept="image/*" onchange="handleFileUpload(event, 'inline')" class="hidden">
                                    <button type="button" onclick="document.getElementById('inline-file-input').click()" class="px-3 py-2 bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 rounded-xl text-xs font-bold" data-hi="अपलोड" data-en="Upload">Upload</button>
                                </div>
                                <div class="grid grid-cols-2 gap-2 pt-1">
                                    <div>
                                        <label class="block text-[10px] text-slate-400 mb-1" data-hi="चौड़ाई" data-en="Width Size">Width Size</label>
                                        <select id="pro-img-width" onchange="autoSaveDraft(); updatePreview();" class="w-full bg-black/50 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-white">
                                            <option value="50%" selected>50% (Compact)</option>
                                            <option value="75%">75% (Medium)</option>
                                            <option value="100%">100% (Full)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-slate-400 mb-1" data-hi="अलाइनमेंट" data-en="Alignment">Alignment</label>
                                        <select id="pro-img-align" onchange="autoSaveDraft(); updatePreview();" class="w-full bg-black/50 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-white">
                                            <option value="center" selected>Center</option>
                                            <option value="left">Left</option>
                                            <option value="right">Right</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-[11px] font-semibold uppercase text-amber-400 mb-1" data-hi="बैकग्राउंड मीडिया (डिफ़ॉल्ट: bg-diwali.gif)" data-en="Background Media (Default: bg-diwali.gif)">Background Media (Default: bg-diwali.gif)</label>
                                <div class="flex space-x-2">
                                    <input type="text" id="pro-bg" value="bg-diwali.gif" placeholder="bg-diwali.gif" oninput="autoSaveDraft(); updatePreview();" class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-xs">
                                    <input type="file" id="bg-file-input" accept="image/*,video/*" onchange="handleFileUpload(event, 'bg')" class="hidden">
                                    <button type="button" onclick="document.getElementById('bg-file-input').click()" class="px-3 py-2 bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 rounded-xl text-xs font-bold" data-hi="अपलोड" data-en="Upload">Upload</button>
                                </div>
                            </div>

                            <div class="pt-2">
                                <button type="button" onclick="validateAndSwitchToMedia()" class="w-full py-3 bg-amber-500 text-slate-950 font-bold rounded-xl text-xs hover:bg-amber-400 transition flex items-center justify-center space-x-1.5">
                                    <span data-hi="अगला टैब: वॉइस और ऑडियो" data-en="Next Tab: Voice & Audio">Next Tab: Voice & Audio</span>
                                    <i class="fa-solid fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Tab 2: Voice & Audio -->
                        <div id="pro-section-media" class="hidden space-y-3">
                            <div class="bg-black/30 p-3.5 rounded-2xl border border-white/10 space-y-2">
                                <label class="block text-[11px] font-semibold uppercase text-amber-400" data-hi="वॉइस नोट रिकॉर्ड करें (10-15 सेकंड)" data-en="Record Voice Note (10-15s Limit)">Record Voice Note (10-15s Limit)</label>
                                <div class="flex items-center space-x-2">
                                    <button type="button" id="record-btn" onclick="toggleRecording()" class="px-3 py-2 bg-red-600 hover:bg-red-500 text-white font-bold rounded-xl text-xs flex items-center space-x-1.5 transition">
                                        <i class="fa-solid fa-microphone"></i>
                                        <span id="record-text" data-hi="रिकॉर्डिंग शुरू करें" data-en="Start Recording">Start Recording</span>
                                    </button>
                                    <span id="record-timer" class="text-xs text-amber-400 font-mono font-bold">00:00 / 00:15</span>
                                </div>
                            </div>

                            <div class="bg-black/30 p-3.5 rounded-2xl border border-white/10 space-y-2">
                                <label class="block text-[11px] font-semibold uppercase text-amber-400" data-hi="बैकग्राउंड संगीत (डिफ़ॉल्ट: bgm-diwali.mp3)" data-en="Background Music (Default: bgm-diwali.mp3)">Background Music (Default: bgm-diwali.mp3)</label>
                                <div class="flex space-x-2">
                                    <input type="text" id="pro-audio-url" value="bgm-diwali.mp3" placeholder="bgm-diwali.mp3" class="w-full px-3 py-2 bg-black/40 border border-white/10 rounded-xl text-white text-xs">
                                </div>
                                <input type="file" id="audio-file-input" accept="audio/*" onchange="handleFileUpload(event, 'audio')" class="w-full text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-amber-500 file:text-slate-950 hover:file:bg-amber-400 cursor-pointer mt-1">
                            </div>

                            <div class="pt-2 flex space-x-2">
                                <button type="button" onclick="switchProTab('details')" class="w-1/3 py-3.5 bg-white/10 hover:bg-white/20 text-white font-bold rounded-2xl text-xs">
                                    <i class="fa-solid fa-arrow-left mr-1"></i> <span data-hi="वापस" data-en="Back">Back</span>
                                </button>
                                <button type="button" onclick="handleProSubmit()" class="w-2/3 py-3.5 bg-gradient-to-r from-amber-500 via-yellow-400 to-amber-600 text-slate-950 font-bold rounded-2xl shadow-lg hover:opacity-95 transition text-sm flex items-center justify-center space-x-2 magnetic-element">
                                    <i class="fa-solid fa-lock text-xs"></i>
                                   <span data-hi="₹0 भुगतान करें और लिंक अनलॉक करें" data-en="Pay ₹0 & Unlock Share Link">Pay ₹0 & Unlock Share Link</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Result Box -->
                    <div id="result-box" class="hidden mt-6 pt-5 border-t border-white/10 text-center space-y-3">
                        <div class="p-2.5 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-400 text-xs" data-hi="शुभकामना लिंक सफलतापूर्वक तैयार हो गया!" data-en="Wish generated & payment verified!">
                            Wish generated & payment verified!
                        </div>
                        <div class="flex items-center space-x-2">
                            <input type="text" id="share-link" readonly class="w-full px-3 py-2 bg-black/50 border border-white/10 rounded-xl text-xs text-slate-300 select-all">
                            <button onclick="copyLink()" class="px-3 py-2 bg-amber-500 text-slate-950 font-bold rounded-xl text-xs hover:bg-amber-400" title="Copy Link">
                                <i class="fa-solid fa-copy"></i>
                            </button>
                        </div>
                        <div class="space-y-3 pt-1">
                            <button onclick="shareOnWhatsAppGenerated()" class="w-full py-4 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-base rounded-2xl shadow-xl flex items-center justify-center space-x-2.5 transition transform active:scale-95 magnetic-element">
                                <i class="fa-brands fa-whatsapp text-2xl"></i>
                                <span data-hi="व्हाट्सएप पर शेयर करें" data-en="Share on WhatsApp">Share on WhatsApp</span>
                            </button>
                            <a id="view-wish-btn" href="#" target="_blank" class="w-full block py-3 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-2xl shadow-md text-xs">
                                <i class="fa-solid fa-eye mr-1"></i> <span data-hi="लाइव विश देखें" data-en="View Live Wish">View Live Wish</span>
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        <?php endif; ?>

    </main>

    <!-- CUSTOM TOAST NOTIFICATION -->
    <div id="custom-toast" class="fixed top-5 right-5 z-50 glass-card px-4 py-3 rounded-2xl glow-gold text-xs text-white border border-amber-500/40 transform translate-y-[-150%] transition-all duration-300 flex items-center space-x-2">
        <i id="toast-icon" class="fa-solid fa-circle-info text-amber-400 text-sm"></i>
        <span id="toast-msg">Notification message here</span>
    </div>

    <!-- AUTH MODAL -->
    <div id="auth-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="glass-card max-w-sm w-full p-6 rounded-3xl glow-gold text-center space-y-4">
            <h3 class="font-cinzel text-xl font-bold text-white" data-hi="यूज़र लॉगिन" data-en="Guest or Password Access">Guest or Password Access</h3>
            <p class="text-xs text-slate-300" data-hi="अपनी एनालिटिक्स हिस्ट्री सुरक्षित रखने के लिए यूज़रनेम दर्ज करें।" data-en="Set a username & optional password to securely access your personal analytics history across devices.">Set a username & optional password to securely access your personal analytics history across devices.</p>
            
            <form onsubmit="handleAuth(event)" class="space-y-3 text-left">
                <div>
                    <label class="block text-[11px] font-semibold uppercase text-amber-400 mb-1" data-hi="यूज़रनेम" data-en="Username">Username</label>
                    <input type="text" id="auth-user" required placeholder="e.g. Abhishek" class="w-full px-3 py-2.5 bg-black/40 border border-white/10 rounded-xl text-white text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase text-amber-400 mb-1" data-hi="पासवर्ड (वैकल्पिक)" data-en="Password (Optional)">Password (Optional)</label>
                    <input type="password" id="auth-pass" placeholder="Leave blank if guest" class="w-full px-3 py-2.5 bg-black/40 border border-white/10 rounded-xl text-white text-xs">
                </div>
                <button type="submit" class="w-full py-3 bg-amber-500 text-slate-950 font-bold rounded-xl text-xs hover:bg-amber-400 transition mt-2" data-hi="आगे बढ़ें" data-en="Continue">
                    Continue
                </button>
            </form>
            <button onclick="closeAuthModal()" class="w-full py-2 text-xs text-slate-400 hover:text-white" data-hi="रद्द करें" data-en="Cancel">Cancel</button>
        </div>
    </div>

    <!-- USER DASHBOARD MODAL -->
    <div id="dashboard-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="glass-card max-w-md w-full p-6 rounded-3xl glow-gold space-y-4">
            <div class="flex justify-between items-center border-b border-white/10 pb-3">
                <h3 class="font-cinzel text-lg font-bold text-amber-400"><i class="fa-solid fa-chart-line mr-1.5"></i> <span data-hi="आपकी पर्सनल एनालिटिक्स" data-en="Your Personal Analytics">Your Personal Analytics</span></h3>
                <button onclick="closeUserDashboard()" class="text-slate-400 hover:text-white text-sm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                <?php if (empty($userWishes)): ?>
                    <div class="text-xs text-slate-400 text-center py-6" data-hi="आपने अभी तक कोई शुभकामना नहीं बनाई है।" data-en="You haven't generated any wishes yet.">You haven't generated any wishes yet.</div>
                <?php else: ?>
                    <?php foreach ($userWishes as $uw): ?>
                        <div class="bg-black/40 p-3 rounded-xl border border-white/5 flex justify-between items-center text-xs">
                            <div>
                                <span class="font-bold text-white"><?php echo htmlspecialchars($uw['from_name']); ?></span> ➔ <span class="font-bold text-amber-400"><?php echo htmlspecialchars($uw['to_name']); ?></span>
                                <div class="text-[10px] text-slate-500"><?php echo $uw['created_at']; ?> (IST) • <?php echo $uw['views']; ?> views</div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <a href="./?id=<?php echo $uw['id']; ?>" target="_blank" class="px-2.5 py-1 bg-amber-500 text-slate-950 font-bold rounded-lg text-[10px]" data-hi="खोलें" data-en="Open">Open</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button onclick="logoutUser()" class="w-full py-2.5 bg-red-500/25 hover:bg-red-500/40 text-red-300 font-semibold rounded-xl text-xs" data-hi="लॉग आउट" data-en="Logout">
                Logout
            </button>
        </div>
    </div>

    <!-- PERSISTENT UPLOAD PROGRESS MODAL -->
    <div id="progress-modal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="glass-card max-w-xs w-full p-6 rounded-3xl glow-gold text-center space-y-4">
            <div class="text-3xl text-amber-400 animate-spin"><i class="fa-solid fa-spinner"></i></div>
            <h3 class="font-cinzel text-lg font-bold text-white" id="progress-title" data-hi="मीडिया कंपाइल हो रहा है..." data-en="Compiling Pro Media...">Compiling Pro Media...</h3>
            <p class="text-xs text-slate-300" id="progress-desc" data-hi="कृपया इस पेज को बंद न करें। प्रक्रिया जारी है।" data-en="Please do not close or refresh this page. Operation in progress.">Please do not close or refresh this page. Operation in progress.</p>
            <div class="w-full bg-black/50 h-2 rounded-full overflow-hidden border border-white/10">
                <div id="progress-bar" class="bg-gradient-to-r from-amber-500 to-yellow-300 h-full w-0 transition-all duration-300"></div>
            </div>
        </div>
    </div>

    <!-- PERSISTENT PRO PAYMENT MODAL -->
    <div id="paywall-modal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="glass-card max-w-sm w-full p-6 rounded-3xl glow-gold text-center space-y-4">
            <div class="inline-block p-3 rounded-full bg-amber-500/20 text-amber-400 text-2xl"><i class="fa-solid fa-crown"></i></div>
            <h3 class="font-cinzel text-xl font-bold text-white" data-hi="प्रो दीपावली पास अनलॉक करें" data-en="Unlock Pro Diwali Pass">Unlock Pro Diwali Pass</h3>
            <p class="text-xs text-slate-300" data-hi="वॉइस नोट और कस्टम ऑडियो सक्रिय करने के लिए <span class='text-amber-400 font-bold'>₹0</span> का भुगतान पूरा करें!" data-en="Complete payment of <span class='text-amber-400 font-bold'>₹0</span> ($2) to activate your voice note, custom audio, and enable sharing for your receiver!">Complete payment of <span class="text-amber-400 font-bold">₹0</span> ($2) to activate your voice note, custom audio, and enable sharing for your receiver!</p>
            
            <div class="space-y-2 pt-2">
                <button onclick="completePaymentAndUnlock()" class="w-full py-3 bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950 font-bold rounded-xl text-xs shadow-lg hover:opacity-90" data-hi="₹0 भुगतान करें और लिंक सक्रिय करें" data-en="Pay ₹0 & Generate Active Link">
                    Pay ₹0 & Generate Active Link
                </button>
            </div>
        </div>
    </div>

    <footer class="relative z-20 py-4 text-center text-xs text-slate-400 space-y-1">
        <div><span data-hi="द्वारा संचालित:" data-en="Powered by">Powered by</span> <a href="https://www.docsansar.com" target="_blank" class="text-amber-400 hover:underline font-semibold">Doc Sansar</a> • <span data-hi="निर्माता:" data-en="Crafted by">Crafted by</span> <a href="https://instagram.com/tabhishek0001" target="_blank" class="text-amber-400 hover:underline font-semibold">tabhishek0001</a></div>
        <div class="text-[10px] text-slate-500">Diwali 2026 Interactive Viral Engine • IST Time Zone</div>
    </footer>

    <script>
        <?php if ($currentWish && $currentWish['is_paid'] == 1): ?>
        fetch('./?action=track_view').catch(e => {});
        window.addEventListener('load', () => triggerFireworks());
        <?php endif; ?>

        // Default English, check URL parameter ?lang=hi or ?lang=en
        let currentLang = 'en'; 
        
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('lang') === 'hi') {
                setLanguage('hi');
            } else {
                setLanguage('en');
            }
        });

        function toggleLanguage() {
            setLanguage(currentLang === 'en' ? 'hi' : 'en');
        }

        function setLanguage(lang) {
            currentLang = lang;
            document.getElementById('lang-btn-text').textContent = currentLang === 'en' ? 'हिंदी' : 'English';
            document.documentElement.setAttribute('lang', currentLang);
            
            // Update URL parameter without page reload so sharing keeps language preference
            const url = new URL(window.location);
            url.searchParams.set('lang', currentLang);
            window.history.replaceState({}, '', url);

            document.querySelectorAll('[data-hi]').forEach(el => {
                const text = el.getAttribute(`data-${currentLang}`);
                if (text) {
                    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                        el.placeholder = text;
                    } else {
                        el.innerHTML = text;
                    }
                }
            });
            updatePreview();
        }

        let activeTier = 'free';
        let currentWishId = null;
        let generatedUrl = '';
        
        let mediaRecorder;
        let audioChunks = [];
        let recordedVoiceBase64 = '';
        let uploadedAudioBase64 = '';
        let recordingInterval;
        let secondsLeft = 15;
        let isUploadingActive = false;

        // Sound Effects
        function playTouchSfx() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(900, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(300, ctx.currentTime + 0.05);
                gain.gain.setValueAtTime(0.08, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.05);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.05);
            } catch(e) {}
        }

        function playSuccessSfx() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                [523.25, 659.25, 783.99, 1046.50].forEach((freq, i) => {
                    setTimeout(() => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'triangle';
                        osc.frequency.setValueAtTime(freq, ctx.currentTime);
                        gain.gain.setValueAtTime(0.06, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start();
                        osc.stop(ctx.currentTime + 0.25);
                    }, i * 70);
                });
            } catch(e) {}
        }

        function playInfoSfx() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                [600, 800].forEach((freq, i) => {
                    setTimeout(() => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(freq, ctx.currentTime);
                        gain.gain.setValueAtTime(0.05, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.1);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start();
                        osc.stop(ctx.currentTime + 0.1);
                    }, i * 90);
                });
            } catch(e) {}
        }

        function playErrorSfx() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(220, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(110, ctx.currentTime + 0.3);
                gain.gain.setValueAtTime(0.08, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } catch(e) {}
        }

        // Subtle Magnetic Attraction & Chingari Sparkle Trail
        let lastSparkTime = 0;
        function handleGlobalMagneticMotion(e) {
            const x = e.clientX;
            const y = e.clientY;

            document.querySelectorAll('.magnetic-element').forEach(el => {
                const rect = el.getBoundingClientRect();
                const elX = rect.left + rect.width / 2;
                const elY = rect.top + rect.height / 2;
                const distX = x - elX;
                const distY = y - elY;
                const distance = Math.sqrt(distX * distX + distY * distY);

                if (distance < 140) {
                    const pullX = distX * 0.05;
                    const pullY = distY * 0.05;
                    el.style.transform = `translate(${pullX}px, ${pullY}px) scale(1.01)`;
                } else {
                    el.style.transform = `translate(0px, 0px) scale(1)`;
                }
            });

            const now = Date.now();
            if (now - lastSparkTime > 35) {
                lastSparkTime = now;
                spawnChingari(x, y);
            }
        }

        function handleGlobalTouchMotion(e) {
            if (e.touches.length > 0) {
                const touch = e.touches[0];
                spawnChingari(touch.clientX, touch.clientY);
            }
        }

        function spawnChingari(x, y) {
            const spark = document.createElement('div');
            spark.className = 'chingari-spark';
            spark.style.left = `${x}px`;
            spark.style.top = `${y}px`;
            const tx = (Math.random() - 0.5) * 140;
            const ty = (Math.random() - 0.5) * 140;
            spark.style.setProperty('--tx', `${tx}px`);
            spark.style.setProperty('--ty', `${ty}px`);
            document.body.appendChild(spark);
            setTimeout(() => spark.remove(), 800);
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('custom-toast');
            const icon = document.getElementById('toast-icon');
            document.getElementById('toast-msg').textContent = message;

            if (type === 'success') {
                icon.className = "fa-solid fa-circle-check text-emerald-400 text-sm";
                playSuccessSfx();
            } else if (type === 'error') {
                icon.className = "fa-solid fa-triangle-exclamation text-red-400 text-sm";
                playErrorSfx();
            } else {
                icon.className = "fa-solid fa-circle-info text-amber-400 text-sm";
                playInfoSfx();
            }

            toast.style.transform = 'translateY(0)';
            setTimeout(() => {
                toast.style.transform = 'translateY(-150%)';
            }, 3500);
        }

        function handleFileUpload(event, type) {
            const file = event.target.files[0];
            if (!file) return;

            if (file.size > 15 * 1024 * 1024) {
                showToast(currentLang === 'hi' ? 'फ़ाइल का साइज़ 15MB से अधिक है।' : 'File size exceeds 15MB limit.', 'error');
                return;
            }

            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onloadend = () => {
                const base64Result = reader.result;
                if (type === 'inline') {
                    document.getElementById('pro-inline-img').value = base64Result;
                    showToast(currentLang === 'hi' ? '✨ इमेज सफलतापूर्वक अपलोड हो गई!' : '✨ Inline photo uploaded successfully!', 'success');
                } else if (type === 'bg') {
                    document.getElementById('pro-bg').value = base64Result;
                    showToast(currentLang === 'hi' ? '✨ बैकग्राउंड मीडिया अपलोड हो गया!' : '✨ Background media uploaded successfully!', 'success');
                } else if (type === 'audio') {
                    uploadedAudioBase64 = base64Result;
                    showToast(currentLang === 'hi' ? '✨ ऑडियो सफलतापूर्वक अपलोड हो गया!' : '✨ Background music uploaded successfully!', 'success');
                }
                autoSaveDraft();
                updatePreview();
            };
        }

        window.addEventListener('load', () => {
            const saved = localStorage.getItem('diwali_draft');
            if (saved) {
                try {
                    const data = JSON.parse(saved);
                    if (document.getElementById('pro-to')) document.getElementById('pro-to').value = data.to || '';
                    if (document.getElementById('pro-from')) document.getElementById('pro-from').value = data.from || '';
                    if (document.getElementById('pro-msg') && data.msg) document.getElementById('pro-msg').value = data.msg;
                    if (document.getElementById('pro-inline-img')) document.getElementById('pro-inline-img').value = data.inlineImg || 'inline-diya.webp';
                    if (document.getElementById('pro-bg')) document.getElementById('pro-bg').value = data.bg || 'bg-diwali.gif';
                    if (document.getElementById('pro-img-width')) document.getElementById('pro-img-width').value = data.imgWidth || '50%';
                    if (document.getElementById('pro-img-align')) document.getElementById('pro-img-align').value = data.imgAlign || 'center';
                    updatePreview();
                } catch(e) {}
            } else {
                updatePreview();
            }
        });

        function autoSaveDraft() {
            const draft = {
                to: document.getElementById('pro-to')?.value || document.getElementById('free-to')?.value || '',
                from: document.getElementById('pro-from')?.value || document.getElementById('free-from')?.value || '',
                msg: document.getElementById('pro-msg')?.value || '',
                inlineImg: document.getElementById('pro-inline-img')?.value || 'inline-diya.webp',
                bg: document.getElementById('pro-bg')?.value || 'bg-diwali.gif',
                imgWidth: document.getElementById('pro-img-width')?.value || '50%',
                imgAlign: document.getElementById('pro-img-align')?.value || 'center'
            };
            localStorage.setItem('diwali_draft', JSON.stringify(draft));
        }

        function applyPresetMessage(type) {
            const msgArea = document.getElementById('pro-msg');
            if (!msgArea) return;
            if (type === 'family') {
                msgArea.value = currentLang === 'hi' ? "दीपावली का यह पावन पर्व हमारे परिवार के जीवन में सुख, शांति, स्वास्थ्य और अपार समृद्धि लेकर आए। शुभ दीपावली परिवार को!" : "May the divine glow of Diwali fill our home and hearts with endless warmth, love, health, and prosperity. Happy Diwali to my wonderful family!";
            } else if (type === 'love') {
                msgArea.value = currentLang === 'hi' ? "तुम मेरी ज़िंदगी का सबसे चमकता प्रकाश हो। यह दीपावली हमारे दिलों को और करीब लाए। आई लव यू!" : "You are the brightest light in my life. May this Diwali bring our hearts closer and gift us moments of eternal joy. I love you!";
            } else if (type === 'success') {
                msgArea.value = currentLang === 'hi' ? "आपको दीपावली की ढेरों शुभकामनाएँ! यह साल आपके लिए नई ऊँचाइयों, सफलता और शानदार तरक्की का हो।" : "Wishing you a blazing Diwali of monumental breakthroughs, unstoppable success, new milestones, and glorious prosperity ahead!";
            }
            autoSaveDraft();
            updatePreview();
            showToast(currentLang === 'hi' ? 'संदेश प्रसेट लागू कर दिया गया है!' : 'Preset message applied!', 'success');
        }

        window.addEventListener('beforeunload', (e) => {
            if (isUploadingActive || isRecording) {
                e.preventDefault();
                e.returnValue = "Upload or recording in progress. Are you sure you want to leave?";
            }
        });

        document.addEventListener('visibilitychange', () => {
            const bgMusic = document.getElementById('bg-music');
            if (!bgMusic) return;
            if (document.hidden) {
                bgMusic.pause();
            } else if (isAudioPlaying) {
                bgMusic.play().catch(e => {});
            }
        });

        let audioInitialized = false;
        function triggerFirstInteractionAudio() {
            if (audioInitialized) return;
            audioInitialized = true;
            const bgMusic = document.getElementById('bg-music');
            if (bgMusic) {
                bgMusic.volume = 0.5;
                bgMusic.play().then(() => {
                    isAudioPlaying = true;
                    updateSoundUI();
                }).catch(e => {});
            }
            triggerFireworks();
        }

        let isAudioPlaying = false;
        function toggleAudio() {
            const bgMusic = document.getElementById('bg-music');
            isAudioPlaying = !isAudioPlaying;
            if (bgMusic) {
                if (isAudioPlaying) bgMusic.play();
                else bgMusic.pause();
            }
            updateSoundUI();
            if (isAudioPlaying) triggerFireworks();
        }

        function adjustVolume(val) {
            const bgMusic = document.getElementById('bg-music');
            if (bgMusic) bgMusic.volume = parseFloat(val);
        }

        function fadeMusicDown() {
            const bgMusic = document.getElementById('bg-music');
            if (bgMusic && !bgMusic.paused) bgMusic.volume = 0.1;
        }

        function fadeMusicUp() {
            const bgMusic = document.getElementById('bg-music');
            const sliderVal = document.getElementById('volume-slider').value;
            if (bgMusic && !bgMusic.paused) bgMusic.volume = parseFloat(sliderVal);
        }

        function updateSoundUI() {
            const icon = document.getElementById('sound-icon');
            icon.className = isAudioPlaying ? "fa-solid fa-pause" : "fa-solid fa-play ml-0.5";
        }

        function switchTier(tier) {
            activeTier = tier;
            const freeForm = document.getElementById('form-free');
            const proForm = document.getElementById('form-pro');
            const tabFree = document.getElementById('tab-free');
            const tabPro = document.getElementById('tab-pro');

            if (tier === 'free') {
                freeForm.classList.remove('hidden');
                proForm.classList.add('hidden');
                tabFree.className = "py-2 text-xs font-bold rounded-xl bg-amber-500 text-slate-950 transition shadow-md flex items-center justify-center space-x-1";
                tabPro.className = "py-2 text-xs font-bold rounded-xl text-slate-400 hover:text-white transition flex items-center justify-center space-x-1";
            } else {
                freeForm.classList.add('hidden');
                proForm.classList.remove('hidden');
                tabPro.className = "py-2 text-xs font-bold rounded-xl bg-amber-500 text-slate-950 transition shadow-md flex items-center justify-center space-x-1";
                tabFree.className = "py-2 text-xs font-bold rounded-xl text-slate-400 hover:text-white transition flex items-center justify-center space-x-1";
            }
            updatePreview();
        }

        function validateAndSwitchToMedia() {
            const to = document.getElementById('pro-to').value.trim();
            const from = document.getElementById('pro-from').value.trim();
            const msg = document.getElementById('pro-msg').value.trim();

            if (!to || !from || !msg) {
                showToast(currentLang === 'hi' ? 'कृपया प्राप्तकर्ता, प्रेषक और संदेश भरें।' : 'Please fill in Recipient, Sender, and Custom Message.', 'error');
                return;
            }
            switchProTab('media');
        }

        function switchProTab(tab) {
            const dSec = document.getElementById('pro-section-details');
            const mSec = document.getElementById('pro-section-media');
            const tD = document.getElementById('pro-tab-details');
            const tM = document.getElementById('pro-tab-media');

            dSec.classList.add('hidden'); mSec.classList.add('hidden');
            tD.className = tM.className = "text-xs font-bold text-slate-400 pb-1 transition";

            if (tab === 'details') {
                dSec.classList.remove('hidden');
                tD.className = "text-xs font-bold text-amber-400 pb-1 border-b-2 border-amber-400 transition";
            } else if (tab === 'media') {
                mSec.classList.remove('hidden');
                tM.className = "text-xs font-bold text-amber-400 pb-1 border-b-2 border-amber-400 transition";
            }
        }

        let isRecording = false;
        function toggleRecording() {
            const btn = document.getElementById('record-btn');
            const text = document.getElementById('record-text');
            const timerDisplay = document.getElementById('record-timer');

            if (!isRecording) {
                audioChunks = [];
                navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
                    mediaRecorder = new MediaRecorder(stream);
                    mediaRecorder.start();
                    isRecording = true;
                    secondsLeft = 15;
                    btn.className = "px-3 py-2 bg-emerald-600 text-white font-bold rounded-xl text-xs flex items-center space-x-1.5 animate-pulse";
                    text.textContent = currentLang === 'hi' ? "रिकॉर्डिंग रोकें" : "Stop Recording";

                    recordingInterval = setInterval(() => {
                        secondsLeft--;
                        timerDisplay.textContent = `00:${secondsLeft < 10 ? '0' : ''}${secondsLeft} / 00:15`;
                        if (secondsLeft <= 0) {
                            stopRecordingAndRelease(stream);
                        }
                    }, 1000);

                    mediaRecorder.ondataavailable = event => audioChunks.push(event.data);
                    mediaRecorder.onstop = () => {
                        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                        const reader = new FileReader();
                        reader.readAsDataURL(audioBlob);
                        reader.onloadend = () => {
                            recordedVoiceBase64 = reader.result;
                            document.getElementById('prev-voice-container').classList.remove('hidden');
                            document.getElementById('prev-audio-playback').src = recordedVoiceBase64;
                            showToast(currentLang === 'hi' ? 'वॉइस नोट रिकॉर्ड हो गया!' : 'Voice note recorded successfully!', 'success');
                        };
                    };
                }).catch(err => showToast(currentLang === 'hi' ? 'माइक्रोफ़ोन एक्सेस अस्वीकृत।' : 'Microphone access denied or not supported.', 'error'));
            } else {
                stopRecordingAndRelease(null);
            }
        }

        function stopRecordingAndRelease(streamObj) {
            if (mediaRecorder && isRecording) {
                mediaRecorder.stop();
                if (mediaRecorder.stream) {
                    mediaRecorder.stream.getTracks().forEach(track => track.stop());
                }
            }
            if (streamObj) {
                streamObj.getTracks().forEach(track => track.stop());
            }
            clearInterval(recordingInterval);
            isRecording = false;
            
            const btn = document.getElementById('record-btn');
            const text = document.getElementById('record-text');
            btn.className = "px-3 py-2 bg-red-600 hover:bg-red-500 text-white font-bold rounded-xl text-xs flex items-center space-x-1.5";
            text.textContent = currentLang === 'hi' ? "पुनः रिकॉर्ड करें (सेव)" : "Record Again (Saved)";
            document.getElementById('record-timer').textContent = "Saved ✓";
        }

        function updatePreview() {
            let toVal = '', fromVal = '', msgVal = '', inlineImgVal = '', imgWidth = '50%', imgAlign = 'center';

            if (activeTier === 'free') {
                toVal = document.getElementById('free-to').value;
                fromVal = document.getElementById('free-from').value;
                msgVal = currentLang === 'hi' ? "दीपावली का यह पावन पर्व आपके जीवन में अपार खुशियाँ, समृद्धि और सफलता लेकर आए।" : "May the festival of lights illuminate your life with boundless joy, prosperity, and success!";
                inlineImgVal = "inline-diya.webp";
            } else {
                toVal = document.getElementById('pro-to').value;
                fromVal = document.getElementById('pro-from').value;
                msgVal = document.getElementById('pro-msg').value;
                inlineImgVal = document.getElementById('pro-inline-img').value || "inline-diya.webp";
                imgWidth = document.getElementById('pro-img-width')?.value || '50%';
                imgAlign = document.getElementById('pro-img-align')?.value || 'center';
            }

            document.getElementById('prev-to').textContent = toVal || (currentLang === 'hi' ? 'प्राप्तकर्ता का नाम' : 'Recipient Name');
            document.getElementById('prev-from').textContent = fromVal || (currentLang === 'hi' ? 'आपका नाम' : 'Your Name');
            document.getElementById('prev-msg').textContent = "\"" + (msgVal || (currentLang === 'hi' ? 'दीपावली की हार्दिक शुभकामनाएँ!' : 'May this Diwali bring joy!')) + "\"";

            const inlineContainer = document.getElementById('prev-inline-container');
            const inlineImg = document.getElementById('prev-inline-img');
            
            inlineImg.src = inlineImgVal;
            inlineContainer.style.width = imgWidth;
            
            if (imgAlign === 'left') {
                inlineContainer.className = "overflow-hidden mr-auto";
            } else if (imgAlign === 'right') {
                inlineContainer.className = "overflow-hidden ml-auto";
            } else {
                inlineContainer.className = "overflow-hidden mx-auto";
            }
        }

        function showProgress(title, desc, callback) {
            isUploadingActive = true;
            const modal = document.getElementById('progress-modal');
            const bar = document.getElementById('progress-bar');
            document.getElementById('progress-title').textContent = title;
            document.getElementById('progress-desc').textContent = desc;
            modal.classList.remove('hidden');
            bar.style.width = '0%';

            setTimeout(() => bar.style.width = '45%', 300);
            setTimeout(() => bar.style.width = '85%', 900);
            setTimeout(() => {
                bar.style.width = '100%';
                setTimeout(() => {
                    modal.classList.add('hidden');
                    isUploadingActive = false;
                    if (callback) callback();
                }, 400);
            }, 1400);
        }

        function submitWish(e, isPro) {
            e.preventDefault();
            showProgress(currentLang === 'hi' ? 'शुभकामना तैयार हो रही है...' : 'Generating Free Wish...', currentLang === 'hi' ? 'ग्रीटिंग कार्ड पैक किया जा रहा है...' : 'Packing your greeting card...', () => {
                let payload = {
                    to: document.getElementById('free-to').value,
                    from: document.getElementById('free-from').value,
                    message: currentLang === 'hi' ? "दीपावली का यह पावन पर्व आपके जीवन में अपार खुशियाँ, समृद्धि और सफलता लेकर आए।" : "May the festival of lights illuminate your life with boundless joy, prosperity, and success!",
                    inline_image: "inline-diya.webp",
                    bg_media: "bg-diwali.gif",
                    is_pro: false
                };
                sendWishToServer(payload);
            });
        }

        function handleProSubmit() {
            const to = document.getElementById('pro-to').value;
            const from = document.getElementById('pro-from').value;
            const message = document.getElementById('pro-msg').value;
            const bgMedia = document.getElementById('pro-bg').value || "bg-diwali.gif";
            const audioUrlInput = document.getElementById('pro-audio-url').value || "bgm-diwali.mp3";
            const inlineImgInput = document.getElementById('pro-inline-img').value || "inline-diya.webp";
            const imgWidth = document.getElementById('pro-img-width').value;
            const imgAlign = document.getElementById('pro-img-align').value;
            
            let mediaType = 'image';
            if (bgMedia.includes('.mp4') || bgMedia.includes('video')) mediaType = 'video';
            else if (bgMedia.includes('.gif')) mediaType = 'gif';

            const finalCustomAudio = uploadedAudioBase64 || audioUrlInput;

            showProgress(currentLang === 'hi' ? 'प्रो मीडिया कंपाइल हो रहा है...' : 'Compiling Pro Media...', currentLang === 'hi' ? 'वॉइस नोट और मीडिया अपलोड हो रहा है...' : 'Uploading voice note & background media. Please wait...', () => {
                let payload = {
                    to: to,
                    from: from,
                    message: message,
                    bg_media: bgMedia,
                    media_type: mediaType,
                    inline_image: inlineImgInput,
                    inline_img_width: imgWidth,
                    inline_img_align: imgAlign,
                    audio_type: 'custom',
                    voice_note: recordedVoiceBase64,
                    custom_audio: finalCustomAudio,
                    is_pro: true
                };

                fetch('./?action=save_wish', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(res => res.json())
                .then(data => {
                    if(data.status === 'success') {
                        currentWishId = data.wish_id;
                        localStorage.removeItem('diwali_draft');
                        document.getElementById('paywall-modal').classList.remove('hidden');
                        showToast(currentLang === 'hi' ? 'प्रो मीडिया सफलतापूर्वक सेव हो गया!' : 'Pro media compiled successfully!', 'success');
                    } else {
                        showToast(data.message || 'Error saving Pro wish.', 'error');
                    }
                }).catch(err => {
                    showToast('Network error during compilation.', 'error');
                });
            });
        }

        function closePaywall() {
            document.getElementById('paywall-modal').classList.add('hidden');
        }

        function completePaymentAndUnlock() {
            closePaywall();
            showProgress(currentLang === 'hi' ? 'सुरक्षित भुगतान हो रहा है...' : 'Processing Secure Payment...', currentLang === 'hi' ? 'शेयर करने योग्य लिंक अनलॉक हो रहा है...' : 'Unlocking shareable Pro link...', () => {
                fetch('./?action=process_payment', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ wish_id: currentWishId })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        const baseUrl = window.location.href.split('?')[0];
                        generatedUrl = `${baseUrl}?id=${currentWishId}&lang=${currentLang}`;
                        document.getElementById('share-link').value = generatedUrl;
                        document.getElementById('view-wish-btn').href = generatedUrl;
                        
                        if(document.getElementById('form-free')) document.getElementById('form-free').classList.add('hidden');
                        if(document.getElementById('form-pro')) document.getElementById('form-pro').classList.add('hidden');
                        document.getElementById('result-box').classList.remove('hidden');
                        
                        showToast(currentLang === 'hi' ? 'भुगतान सत्यापित और लिंक अनलॉक हो गया!' : 'Payment verified & share link unlocked!', 'success');
                        triggerFireworks();
                    }
                });
            });
        }

        function sendWishToServer(payload) {
            fetch('./?action=save_wish', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    const baseUrl = window.location.href.split('?')[0];
                    generatedUrl = `${baseUrl}?id=${data.wish_id}&lang=${currentLang}`;
                    document.getElementById('share-link').value = generatedUrl;
                    document.getElementById('view-wish-btn').href = generatedUrl;
                    
                    if(document.getElementById('form-free')) document.getElementById('form-free').classList.add('hidden');
                    if(document.getElementById('form-pro')) document.getElementById('form-pro').classList.add('hidden');
                    document.getElementById('result-box').classList.remove('hidden');
                    
                    showToast(currentLang === 'hi' ? 'विश लिंक सफलतापूर्वक बन गया!' : 'Free wish generated successfully!', 'success');
                    triggerFireworks();
                } else {
                    showToast(data.message || 'Error saving wish.', 'error');
                }
            });
        }

        function openAuthModal() { document.getElementById('auth-modal').classList.remove('hidden'); }
        function closeAuthModal() { document.getElementById('auth-modal').classList.add('hidden'); }
        function openUserDashboard() { document.getElementById('dashboard-modal').classList.remove('hidden'); }
        function closeUserDashboard() { document.getElementById('dashboard-modal').classList.add('hidden'); }

        function handleAuth(e) {
            e.preventDefault();
            const username = document.getElementById('auth-user').value;
            const password = document.getElementById('auth-pass').value;

            fetch('./?action=register_or_login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(currentLang === 'hi' ? 'लॉगिन सफल!' : 'Logged in successfully!', 'success');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.message || 'Authentication error.', 'error');
                }
            });
        }

        function logoutUser() {
            fetch('./?action=logout').then(() => {
                showToast(currentLang === 'hi' ? 'लॉग आउट हो गए।' : 'Logged out successfully.', 'info');
                setTimeout(() => location.reload(), 600);
            });
        }

        function copyLink() {
            const copyText = document.getElementById('share-link');
            copyText.select();
            navigator.clipboard.writeText(copyText.value);
            showToast(currentLang === 'hi' ? '✨ लिंक क्लिपबोर्ड पर कॉपी हो गया!' : '✨ Magical link copied to clipboard!', 'success');
        }

        function shareOnWhatsApp() {
            fetch('./?action=track_share').catch(e => {});
            const text = encodeURIComponent(currentLang === 'hi' ? "✨ आपको एक विशेष दीपावली शुभकामना मिली है! यहाँ देखें:" : "✨ You've received a special Diwali greeting! Open it up:");
            window.open(`https://api.whatsapp.com/send?text=${text}%20${encodeURIComponent(window.location.href)}`, '_blank');
        }

        function shareOnWhatsAppGenerated() {
            fetch('./?action=track_share').catch(e => {});
            const text = encodeURIComponent(currentLang === 'hi' ? "🪔 नमस्ते! मैंने आपके लिए एक खास दीपावली सरप्राइज भेजा है। यहाँ खोलें:" : "🪔 Hey! I sent a special Diwali surprise for you. Open it here:");
            window.open(`https://api.whatsapp.com/send?text=${text}%20${encodeURIComponent(generatedUrl)}`, '_blank');
        }

        function triggerFireworks() {
            confetti({ particleCount: 150, spread: 80, origin: { y: 0.7 }, colors: ['#FFD700', '#FF8C00', '#FF4500', '#DA70D6'] });
        }
    </script>
</body>
</html>