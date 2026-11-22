package com.freepbx.qstat;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.os.Build;
import androidx.annotation.NonNull;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import android.app.Notification;

public class QStatFirebaseMessagingService extends FirebaseMessagingService {
    public static final String CHANNEL_ID = "qstat_missed_calls";
    @Override public void onNewToken(@NonNull String token) {
        super.onNewToken(token);
        getSharedPreferences("fcm", MODE_PRIVATE).edit().putString("last_token", token).apply();
        QStatPushRegistrar.register(this, token);
    }
    @Override public void onMessageReceived(@NonNull RemoteMessage message) {
        // When FCM displays a notification in the background, Android handles it automatically.
        // This path handles foreground messages and data-only test messages.
        RemoteMessage.Notification payload = message.getNotification();
        String title = payload != null && payload.getTitle() != null ? payload.getTitle() : "FreePBX QStat";
        String body = payload != null && payload.getBody() != null ? payload.getBody() : message.getData().get("body");
        if (body == null) body = "Новое уведомление";
        NotificationManager nm = getSystemService(NotificationManager.class);
        if (Build.VERSION.SDK_INT >= 26) nm.createNotificationChannel(new NotificationChannel(CHANNEL_ID,"Пропущенные звонки",NotificationManager.IMPORTANCE_HIGH));
        Intent intent = new Intent(this, MainActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        intent.putExtra("qstat_missed",true);
        PendingIntent pi = PendingIntent.getActivity(this,0,intent,PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification n = new Notification.Builder(this, CHANNEL_ID).setSmallIcon(android.R.drawable.sym_call_missed)
            .setContentTitle(title).setContentText(body).setAutoCancel(true).setContentIntent(pi).build();
        try { nm.notify((int)(System.currentTimeMillis() & 0x7fffffff), n); } catch (SecurityException ignored) {}
    }
}
