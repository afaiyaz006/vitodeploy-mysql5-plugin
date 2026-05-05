{{-- Always create both `'user'@'localhost'` (matches socket connections) and
     `'user'@'127.0.0.1'` (matches TCP loopback). MySQL special-cases the
     literal "localhost" to mean socket-only, so an app connecting via
     `-h 127.0.0.1` against a `'@localhost'` grant gets
     `ERROR 1130: Host '127.0.0.1' is not allowed`. Creating the pair makes
     both connection paths work for every Vito-managed DB user. If the user
     supplied a remote host (e.g. `%`, `1.2.3.4`), add it as a third row.

     `GRANT USAGE` is the pre-5.7 create-or-update idiom: it creates the
     user with no privileges (USAGE = no privileges) when missing and
     updates the password if the user already exists. Works in 5.5 / 5.6 /
     5.7. Subsequent linking to a database is handled by link.blade.php. --}}
@php
    $hosts = array_values(array_unique(['localhost', '127.0.0.1', $host]));
@endphp

@foreach ($hosts as $h)
if ! sudo mysql -e "GRANT USAGE ON *.* TO '{{ $username }}'@'{{ $h }}' IDENTIFIED BY '{{ $password }}'"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi
@endforeach

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

echo "Command executed"
