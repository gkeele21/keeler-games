<p>Hi {{ $user->first_name ?: 'there' }},</p>

<p>You're in for <strong>{{ $event->name }}</strong>.</p>

<p>Keep this email — this link gets you back to your picks on any device, with no password:</p>

<p><a href="{{ $magicLink }}">{{ $magicLink }}</a></p>

<p>Use the same link next time rather than signing up again, so your answers and your place on the leaderboard stay with you.</p>

<p>If you weren't expecting this, you can ignore this email.</p>
