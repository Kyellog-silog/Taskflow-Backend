<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Invitation</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 10px 10px 0 0;
        }
        .content {
            background: white;
            padding: 30px;
            border: 1px solid #e1e8ed;
        }
        .team-info {
            background: #f8fafc;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #667eea;
        }
        .cta-button {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
        }
        .footer {
            background: #f8fafc;
            padding: 20px;
            text-align: center;
            border-radius: 0 0 10px 10px;
            color: #666;
            font-size: 14px;
        }
        .expire-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚀 TaskFlow Team Invitation</h1>
        <p>You've been invited to collaborate!</p>
    </div>

    <div class="content">
        <h2>Hi there! 👋</h2>
        
        <p><strong>{{ $inviterName }}</strong> has invited you to join their team on TaskFlow.</p>

        <div class="team-info">
            <h3>{{ $teamName }}</h3>
            @if($teamDescription)
                <p>{{ $teamDescription }}</p>
            @endif
            <p><strong>Your role:</strong> {{ ucfirst($role) }}</p>
        </div>

        <p>TaskFlow is a collaborative project management tool where teams can organize tasks, track progress, and work together seamlessly.</p>

        <div style="text-align: center;">
            <a href="{{ $acceptUrl }}" class="cta-button">Accept Invitation</a>
        </div>

        <div class="expire-info">
            <strong>⏰ This invitation expires on {{ $expiresAt }}</strong>
        </div>

        <h3>What happens next?</h3>
        <ol>
            <li>Click the "Accept Invitation" button above</li>
            <li>Sign in to your TaskFlow account (or create one if you don't have it)</li>
            <li>Start collaborating with your team immediately!</li>
        </ol>

        <p>If you don't want to join this team, you can simply ignore this email.</p>
    </div>

    <div class="footer">
        <p>This invitation was sent by {{ $inviterName }} through TaskFlow.</p>
        <p>If you have trouble clicking the button, copy and paste this URL into your browser:</p>
        <p style="word-break: break-all; color: #667eea;">{{ $acceptUrl }}</p>
    </div>
</body>
</html>
