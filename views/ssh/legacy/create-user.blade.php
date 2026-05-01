{{-- MySQL 5.5 / 5.6 don't support `CREATE USER IF NOT EXISTS` (added in
     5.7.6). The pre-5.7 idiom for create-or-update is `GRANT USAGE`, which
     creates the user with no privileges (USAGE = no privileges) when missing
     and updates the password if the user already exists. --}}
if ! sudo mysql -e "GRANT USAGE ON *.* TO '{{ $username }}'@'{{ $host }}' IDENTIFIED BY '{{ $password }}'"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

echo "Command executed"
