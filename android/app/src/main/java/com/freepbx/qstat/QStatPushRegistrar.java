package com.freepbx.qstat;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyProperties;
import org.json.JSONObject;
import java.net.HttpURLConnection;
import java.net.URL;
import java.io.OutputStream;
import java.io.InputStream;
import java.security.KeyStore;
import javax.crypto.Cipher;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

public final class QStatPushRegistrar {
 private QStatPushRegistrar() {}
 public static void register(Context context, String fcm) {
  new Thread(() -> {
   try {
    SharedPreferences p=context.getSharedPreferences("MainActivity",Context.MODE_PRIVATE);
    if(!p.getBoolean("connected",false))return;
    String base=p.getString("url","");String data=p.getString("token_data","");
    if(base.isEmpty()||data.isEmpty())return;
    KeyStore ks=KeyStore.getInstance("AndroidKeyStore");ks.load(null);
    SecretKey key=(SecretKey)ks.getKey("qstat-key",null);
    Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");
    cipher.init(Cipher.DECRYPT_MODE,key,new GCMParameterSpec(128,android.util.Base64.decode(p.getString("token_iv",""),0)));
    String auth=new String(cipher.doFinal(android.util.Base64.decode(data,0)),"UTF-8");
    if(auth.isEmpty())return;
    String installation=p.getString("installation_id","");
    if(installation.isEmpty()){installation=java.util.UUID.randomUUID().toString();p.edit().putString("installation_id",installation).apply();}
    JSONObject body=new JSONObject();body.put("installation_id",installation);body.put("platform","android");body.put("fcm_token",fcm);
    HttpURLConnection con=(HttpURLConnection)new URL(MainActivity.apiUrl(base)+"?action=register_push").openConnection();
    con.setRequestMethod("POST");con.setConnectTimeout(12000);con.setReadTimeout(12000);con.setDoOutput(true);
    con.setRequestProperty("Authorization","Bearer "+auth);con.setRequestProperty("Content-Type","application/json; charset=utf-8");
    try {try(OutputStream out=con.getOutputStream()){out.write(body.toString().getBytes("UTF-8"));}
      if(con.getResponseCode()!=200)throw new Exception("HTTP "+con.getResponseCode());
      p.edit().putString("registered_fcm",fcm).apply();
    }finally{con.disconnect();}
   }catch(Exception e){android.util.Log.w("QStat","FCM refresh registration deferred: "+e.getMessage());}
  }).start();
 }
}
