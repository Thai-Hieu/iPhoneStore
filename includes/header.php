<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>iphonestore</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Icon -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background-color: #f4f6f9;
        }
        .sidebar {
            min-height: 100vh;
            background: rgba(0, 0, 0, 0.85);
        }
        .sidebar a {
            color: #fff;
            text-decoration: none;
            padding: 12px;
            display: block;
        }
        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.15);
        }
    </style>
</head>
<body>
