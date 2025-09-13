<?php
echo "<h1>Kiểm tra kết nối đến máy chủ Trip.com...</h1>";

$host = 'ttdopen.ctrip.com';
$port = 443; // Cổng cho HTTPS

// Sử dụng fsockopen để kiểm tra kết nối TCP
$connection = @fsockopen("ssl://" . $host, $port, $errno, $errstr, 10); // 10 giây timeout

if (is_resource($connection)) {
    echo "<p style='color:green; font-weight:bold;'>THÀNH CÔNG: Có thể kết nối đến $host trên cổng $port.</p>";
    echo "<p>Điều này có nghĩa là không có tường lửa nào chặn kết nối đi từ máy chủ của bạn.</p>";
    fclose($connection);
} else {
    echo "<p style='color:red; font-weight:bold;'>THẤT BẠI: Không thể kết nối đến $host trên cổng $port.</p>";
    echo "<p><b>Lỗi:</b> ($errno) $errstr</p>";
    echo "<p><b>Nguyên nhân có thể:</b></p>";
    echo "<ul>";
    echo "<li>Tường lửa trên máy chủ của bạn (hoặc của nhà cung cấp hosting) đang chặn các kết nối đi (outbound connection).</li>";
    echo "<li>Có vấn đề về mạng giữa máy chủ của bạn và máy chủ của Trip.com.</li>";
    echo "</ul>";
    echo "<p><b>Hành động:</b> Vui lòng liên hệ với nhà cung cấp hosting của bạn và yêu cầu họ kiểm tra/mở kết nối đi đến địa chỉ <b>$host</b> trên cổng <b>$port</b>.</p>";
}
