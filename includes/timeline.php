<?php

function addTimeline(
    mysqli $conn,
    int $complaintId,
    string $action,
    string $details = '',
    string $userType = 'System',
    string $userName = ''
): bool {
    $action = mysqli_real_escape_string($conn, trim($action));
    $details = mysqli_real_escape_string($conn, trim($details));
    $userType = mysqli_real_escape_string($conn, trim($userType));
    $userName = mysqli_real_escape_string($conn, trim($userName));

    $allowedUserTypes = ['Admin', 'Agent', 'System'];

    if (!in_array($userType, $allowedUserTypes, true)) {
        $userType = 'System';
    }

    $sql = "
        INSERT INTO complaint_timeline
        (
            complaint_id,
            action,
            details,
            user_type,
            user_name
        )
        VALUES
        (
            $complaintId,
            '$action',
            '$details',
            '$userType',
            '$userName'
        )
    ";

    return mysqli_query($conn, $sql) === true;
}