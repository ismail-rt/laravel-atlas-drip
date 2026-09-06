<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Preferences</title>
    <style>
        *, ::before, ::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .card {
            background-color: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.03);
            max-width: 28rem;
            width: 100%;
            padding: 2.25rem;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .icon-wrapper {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }
        .icon-unsubscribed {
            background-color: #f1f5f9;
            color: #475569;
        }
        .icon-resubscribed {
            background-color: #ecfdf5;
            color: #059669;
        }
        h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }
        p {
            font-size: 0.95rem;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 1.25rem;
        }
        .notice-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.85rem 1rem;
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 1.5rem;
            text-align: left;
        }
        .notice-box strong {
            color: #334155;
        }
        .btn {
            display: inline-block;
            width: 100%;
            padding: 0.75rem 1.25rem;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
            cursor: pointer;
            border: none;
        }
        .btn-secondary {
            background-color: #f1f5f9;
            color: #334155;
        }
        .btn-secondary:hover {
            background-color: #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="card">
        @if (($status ?? 'unsubscribed') === 'resubscribed')
            <div class="icon-wrapper icon-resubscribed">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h1>Preferences Updated</h1>
            <p>You have been successfully resubscribed to updates and product tips.</p>
        @else
            <div class="icon-wrapper icon-unsubscribed">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6L6 18M6 6l12 12"></path>
                </svg>
            </div>
            <h1>You are unsubscribed</h1>
            <p>You will no longer receive lifecycle, onboarding, or marketing emails from us.</p>
            <div class="notice-box">
                <strong>Important:</strong> You will still receive vital transactional emails, including password resets, purchase receipts, and security alerts.
            </div>
            @if (isset($recipientId))
                <form method="POST" action="{{ \Illuminate\Support\Facades\URL::signedRoute('lad.resubscribe', ['recipient' => $recipientId]) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Unsubscribed by mistake? Resubscribe</button>
                </form>
            @endif
        @endif
    </div>
</body>
</html>
