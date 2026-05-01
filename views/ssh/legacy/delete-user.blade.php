{{-- MySQL 5.5 / 5.6 don't support `DROP USER IF EXISTS` (added in 5.7.8).
     Pre-check the user's existence in mysql.user and only DROP if present,
     so the operation is idempotent like the modern syntax. --}}
EXISTS=$(sudo mysql -BN -e "SELECT 1 FROM mysql.user WHERE User='{{ $username }}' AND Host='{{ $host }}' LIMIT 1")
if [ "$EXISTS" = "1" ]; then
    if ! sudo mysql -e "DROP USER '{{ $username }}'@'{{ $host }}'"; then
        echo 'VITO_SSH_ERROR' && exit 1
    fi
fi

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

echo "Command executed"
