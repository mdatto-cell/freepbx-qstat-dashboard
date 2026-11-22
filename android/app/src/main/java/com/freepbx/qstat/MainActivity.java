package com.freepbx.qstat;

import android.app.*;
import com.google.firebase.messaging.FirebaseMessaging;
import android.os.*;
import android.content.*;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.media.MediaPlayer;
import android.view.*;
import android.widget.*;
import org.json.*;
import java.net.*;
import java.io.*;
import java.text.*;
import java.util.*;
import java.util.concurrent.*;
import javax.crypto.*;
import javax.crypto.spec.*;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import java.security.KeyStore;

public class MainActivity extends Activity {
 final int teal=Color.rgb(0,105,112); int bg=0xfff2f2f7; boolean dark=false; String mobileName="DashBoard"; final ExecutorService executor=Executors.newFixedThreadPool(3);
 LinearLayout root, list, chips, kpis, tabBar; ScrollView scroll; TextView tabIncoming, tabOutgoing; boolean pulling=false; float pullStart=0; TextView countLabel, journalLabel; String address="", token="", section="incoming", status="", date=new SimpleDateFormat("yyyy-MM-dd",Locale.US).format(new Date());
 int total=0, offset=0, generation=0; boolean loading=false; JSONObject currentKpi=new JSONObject(); ArrayList<JSONObject> calls=new ArrayList<>(); JSONArray agents=new JSONArray(); boolean pushLanding=false;
 String[] incoming={"","answered","missed_no_callback","missed_callback_answered","missed_callback_noanswer","missed_callback_self","miss_night_1","miss_night_2"};
 String[] incomingNames={"Все","Отвеченные","Пропущенные","Перезвонили","Не дозвонились","Клиент перезвонил","Ночь: нужно перезвонить","Ночь: перезвонит сам"};
 String[] outgoing={"","ANSWERED","NO ANSWER","BUSY"}; String[] outgoingNames={"Все","Ответили","Не ответили","Занят"};
 public void onCreate(Bundle b){super.onCreate(b);getWindow().setStatusBarColor(teal);getWindow().setNavigationBarColor(Color.BLACK); address=normalizeBase(getPreferences(0).getString("url",""));token=loadToken();dark=getPreferences(0).getBoolean("dark_theme",false);applyTheme();mobileName=getPreferences(0).getString("mobile_name","DashBoard");setupPush();pushLanding=isPushIntent(getIntent());if(getPreferences(0).getBoolean("connected",false)){if(pushLanding)selectMissed();dashboard();syncConnected();}else login();}
 boolean isPushIntent(Intent i){return i!=null && (i.getBooleanExtra("qstat_missed",false) || "missed_call".equals(i.getStringExtra("type")) || "incoming".equals(i.getStringExtra("screen")) || "com.freepbx.qstat.OPEN_MISSED".equals(i.getAction()));}
 static String normalizeBase(String value){String v=value==null?"":value.trim();while(v.endsWith("/"))v=v.substring(0,v.length()-1);if(v.endsWith("/api.php"))v=v.substring(0,v.length()-8);return v;}
 static String apiUrl(String base){return normalizeBase(base)+"/api.php";}
 void applyTheme(){bg=dark?0xff10151b:0xfff2f2f7;getWindow().setNavigationBarColor(bg);}
 int foreground(){return dark?0xfff2f5f7:0xff202b32;}
 int panel(){return dark?0xff202a34:Color.WHITE;}
 void fetchMobileName(String base,String auth){executor.execute(()->{try{JSONObject result=request(base,auth,"action=config",false);String name=result.optString("mobile_name", "DashBoard").trim();if(name.isEmpty())name="DashBoard";final String display=name;getPreferences(0).edit().putString("mobile_name",display).apply();runOnUiThread(()->{mobileName=display;if(root!=null&&getPreferences(0).getBoolean("connected",false))dashboard();});}catch(Exception e){android.util.Log.w("QStat","Mobile name unavailable: "+e.getMessage());}});}
 void selectMissed(){section="incoming";status="missed_no_callback";date=new SimpleDateFormat("yyyy-MM-dd",Locale.US).format(new Date());}
 @Override protected void onNewIntent(Intent intent){super.onNewIntent(intent);setIntent(intent);if(isPushIntent(intent)){pushLanding=true;selectMissed();if(getPreferences(0).getBoolean("connected",false))dashboard();}}
 String installationId(){String id=getPreferences(0).getString("installation_id","");if(id.isEmpty()){id=UUID.randomUUID().toString();getPreferences(0).edit().putString("installation_id",id).apply();}return id;}
 void syncConnected(){if(address.isEmpty())return;fetchMobileName(address,token);String base=address,auth=token;FirebaseMessaging.getInstance().getToken().addOnSuccessListener(fcm->{if(fcm!=null&&!fcm.isEmpty())registerPush(base,auth,fcm);});executor.execute(()->{try{JSONObject response=request(base,auth,"action=agents",false);JSONArray list=response.optJSONArray("agents");if(list!=null)runOnUiThread(()->agents=list);}catch(Exception e){android.util.Log.w("QStat","Agent list unavailable: "+e.getMessage());}});}
 void registerPush(String base,String auth,String fcm){if(base.isEmpty()||auth.isEmpty()||fcm.isEmpty())return;executor.execute(()->{try{JSONObject data=new JSONObject();data.put("installation_id",installationId());data.put("platform","android");data.put("fcm_token",fcm);JSONObject result=postJson(base,"action=register_push",auth,data);if(!result.optBoolean("ok"))throw new Exception(result.toString());getPreferences(0).edit().putString("registered_fcm",fcm).apply();}catch(Exception e){android.util.Log.w("QStat","FCM registration failed: "+e.getMessage());}});}
 void unregisterPush(String base,String auth){if(base.isEmpty()||auth.isEmpty())return;executor.execute(()->{try{JSONObject data=new JSONObject();data.put("installation_id",installationId());postJson(base,"action=unregister_push",auth,data);}catch(Exception e){android.util.Log.w("QStat","FCM unregister failed: "+e.getMessage());}});}
 void setupPush(){
  NotificationManager nm=getSystemService(NotificationManager.class);
  if(Build.VERSION.SDK_INT>=26) nm.createNotificationChannel(new NotificationChannel("qstat_missed_calls","Пропущенные звонки",NotificationManager.IMPORTANCE_HIGH));
  if(Build.VERSION.SDK_INT>=33 && checkSelfPermission("android.permission.POST_NOTIFICATIONS")!=android.content.pm.PackageManager.PERMISSION_GRANTED)
   requestPermissions(new String[]{"android.permission.POST_NOTIFICATIONS"},19);
 }
 void showSettings(){
  final String[] themes={"Светлая", "Тёмная"};
  new AlertDialog.Builder(this).setTitle("Настройки")
   .setMessage(address+"\n\nFCM-токен регистрируется автоматически при подключении.")
   .setPositiveButton("Готово",(a,b)->{})
   .setNeutralButton("Тема оформления",(a,b)->new AlertDialog.Builder(this).setTitle("Тема оформления").setSingleChoiceItems(themes,dark?1:0,(d,choice)->{d.dismiss();boolean selected=choice==1;if(dark!=selected){dark=selected;getPreferences(0).edit().putBoolean("dark_theme",dark).apply();recreate();}}).setNegativeButton("Отмена",null).show())
   .setNegativeButton("Отключить",(a,b)->{generation++;unregisterPush(address,token);getPreferences(0).edit().putBoolean("connected",false).apply();saveToken("");token="";login();}).show();
 }
 void showFcmToken(){
  FirebaseMessaging.getInstance().getToken().addOnCompleteListener(task->{
   if(!task.isSuccessful() || task.getResult()==null){alert("Не удалось получить FCM-токен. Проверьте интернет и Google Play services.");return;}
   String fcmToken=task.getResult();
   new AlertDialog.Builder(this).setTitle("FCM-токен устройства")
    .setMessage(fcmToken)
    .setPositiveButton("Копировать",(d,w)->{
      android.content.ClipboardManager clipboard=(android.content.ClipboardManager)getSystemService(CLIPBOARD_SERVICE);
      clipboard.setPrimaryClip(ClipData.newPlainText("FCM device token",fcmToken));
      Toast.makeText(this,"FCM-токен скопирован",Toast.LENGTH_SHORT).show();
    }).setNegativeButton("Закрыть",null).show();
  });
 }
 int dp(int x){return (int)(x*getResources().getDisplayMetrics().density+.5f);} GradientDrawable shape(int color,int radius){GradientDrawable d=new GradientDrawable();d.setColor(color);d.setCornerRadius(dp(radius));return d;}
 LinearLayout vertical(){LinearLayout l=new LinearLayout(this);l.setOrientation(1);return l;} LinearLayout horizontal(){LinearLayout l=new LinearLayout(this);l.setOrientation(0);l.setGravity(Gravity.CENTER_VERTICAL);return l;}
 TextView text(String s,int size,boolean bold){TextView t=new TextView(this);t.setText(s);t.setTextSize(size);t.setTextColor(foreground());if(bold)t.setTypeface(null,Typeface.BOLD);return t;}
 void pad(View v,int a){v.setPadding(dp(a),dp(a),dp(a),dp(a));} void add(LinearLayout parent,View v){parent.addView(v);} void space(LinearLayout l,int h){View v=new View(this);l.addView(v,new LinearLayout.LayoutParams(1,dp(h)));}
 void base(String title){root=vertical();root.setBackgroundColor(bg);setContentView(root);TextView bar=text(title,19,true);bar.setTextColor(Color.WHITE);bar.setBackgroundColor(teal);pad(bar,17);add(root,bar);}
 void login(){base("DashBoard");LinearLayout form=vertical();pad(form,22);add(root,form);space(form,22);add(form,text("Статистика телефонных звонков",18,true));space(form,22);add(form,text("Адрес API",14,true));EditText url=new EditText(this);url.setSingleLine();url.setHint("https://pbx.example.ru/qstat");url.setText(address);add(form,url);space(form,12);add(form,text("Токен",14,true));EditText secret=new EditText(this);secret.setSingleLine();secret.setInputType(129);secret.setText(token);add(form,secret);space(form,12);add(form,text("Если авторизация API отключена, токен можно оставить пустым.",13,false));space(form,18);Button connect=new Button(this);connect.setText("Подключиться");add(form,connect);connect.setOnClickListener(v->{String u=normalizeBase(url.getText().toString()),t=secret.getText().toString();try{URI uri=new URI(u);if(!("http".equalsIgnoreCase(uri.getScheme())||"https".equalsIgnoreCase(uri.getScheme()))||uri.getHost()==null)throw new Exception();}catch(Exception e){alert("Проверьте полный адрес API");return;}connect.setEnabled(false);executor.execute(()->{try{request(u,t,"type=incoming&date_from="+date+"&date_to="+date+"&limit=1&offset=0");runOnUiThread(()->{address=u;token=t;saveToken(t);getPreferences(0).edit().putString("url",u).putBoolean("connected",true).apply();if(pushLanding)selectMissed();dashboard();syncConnected();});}catch(Exception e){runOnUiThread(()->{connect.setEnabled(true);alert(e.getMessage());});}});});}
 void dashboard(){
 root=vertical();root.setBackgroundColor(bg);getWindow().getDecorView().setSystemUiVisibility(0);if(android.os.Build.VERSION.SDK_INT>=30)getWindow().setDecorFitsSystemWindows(true);setContentView(root);getWindow().setStatusBarColor(bg);getWindow().setNavigationBarColor(bg);getWindow().getDecorView().setSystemUiVisibility(dark?0:View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR);
 LinearLayout header=horizontal();header.setPadding(dp(12),dp(4),dp(12),dp(2));add(root,header);
 TextView title=text(mobileName,18,true);title.setGravity(Gravity.CENTER);header.addView(title,new LinearLayout.LayoutParams(0,dp(38),1));
 TextView calendar=text("▦",24,true);calendar.setTextColor(teal);calendar.setGravity(Gravity.CENTER);header.addView(calendar,new LinearLayout.LayoutParams(dp(38),dp(38)));calendar.setOnClickListener(v->{Calendar c=Calendar.getInstance();try{c.setTime(new SimpleDateFormat("yyyy-MM-dd",Locale.US).parse(date));}catch(Exception ignored){}new DatePickerDialog(this,(view,y,m,day)->{date=String.format(Locale.US,"%04d-%02d-%02d",y,m+1,day);reload();},c.get(Calendar.YEAR),c.get(Calendar.MONTH),c.get(Calendar.DAY_OF_MONTH)).show();});
 TextView settings=text("⚙",24,false);settings.setGravity(Gravity.CENTER);settings.setTextColor(teal);header.addView(settings,new LinearLayout.LayoutParams(dp(38),dp(38)));settings.setOnClickListener(v->showSettings());
 tabBar=horizontal();tabBar.setBackground(shape(dark?0xff303c48:0xffe3e3e9,9));tabBar.setPadding(dp(3),dp(3),dp(3),dp(3));LinearLayout.LayoutParams tabsLp=new LinearLayout.LayoutParams(-1,dp(38));tabsLp.setMargins(dp(12),dp(2),dp(12),dp(4));root.addView(tabBar,tabsLp);
 tabIncoming=text("Входящие",14,false);tabOutgoing=text("Исходящие",14,false);for(TextView t:new TextView[]{tabIncoming,tabOutgoing}){t.setGravity(Gravity.CENTER);tabBar.addView(t,new LinearLayout.LayoutParams(0,-1,1));}tabIncoming.setOnClickListener(v->switchSection("incoming"));tabOutgoing.setOnClickListener(v->switchSection("outgoing"));renderTabs();
 HorizontalScrollView strip=new HorizontalScrollView(this);strip.setHorizontalScrollBarEnabled(false);add(root,strip);chips=horizontal();chips.setPadding(dp(9),dp(2),dp(9),dp(3));strip.addView(chips);
 kpis=horizontal();kpis.setPadding(dp(9),dp(2),dp(9),dp(4));add(root,kpis);
 LinearLayout heading=horizontal();heading.setPadding(dp(12),dp(4),dp(12),dp(4));add(root,heading);journalLabel=text("",12,true);journalLabel.setTextColor(0xff8b8b91);heading.addView(journalLabel,new LinearLayout.LayoutParams(0,-2,1));countLabel=text("",12,true);countLabel.setGravity(Gravity.RIGHT);countLabel.setTextColor(0xff8b8b91);heading.addView(countLabel,new LinearLayout.LayoutParams(-2,-2));
 scroll=new ScrollView(this);scroll.setFillViewport(true);scroll.setVerticalScrollBarEnabled(false);root.addView(scroll,new LinearLayout.LayoutParams(-1,0,1));list=vertical();list.setPadding(dp(10),dp(1),dp(10),dp(12));scroll.addView(list);
 scroll.setOnTouchListener((v,event)->{switch(event.getActionMasked()){case android.view.MotionEvent.ACTION_DOWN:pullStart=event.getY();pulling=scroll.getScrollY()==0;break;case android.view.MotionEvent.ACTION_UP:if(pulling&&scroll.getScrollY()==0&&event.getY()-pullStart>dp(85)){pulling=false;reload();return true;}pulling=false;break;case android.view.MotionEvent.ACTION_CANCEL:pulling=false;break;}return false;});
 renderChips();reload();}
 void renderTabs(){if(tabIncoming==null)return;boolean in=section.equals("incoming");tabIncoming.setBackground(shape(in?panel():(dark?0xff303c48:0xffe3e3e9),9));tabOutgoing.setBackground(shape(in?(dark?0xff303c48:0xffe3e3e9):panel(),9));tabIncoming.setTypeface(null,in?Typeface.BOLD:Typeface.NORMAL);tabOutgoing.setTypeface(null,in?Typeface.NORMAL:Typeface.BOLD);}
 void switchSection(String s){if(section.equals(s))return;section=s;status="";renderTabs();renderChips();reload();}
 int statusColor(String s){
  if(s.equals("missed_no_callback") || s.equals("miss_night_1"))return 0xffc03542;
  if(s.equals("missed_callback_answered") || s.equals("miss_night_2"))return 0xff418322;
  if(s.equals("missed_callback_noanswer") || s.equals("NO ANSWER"))return 0xffb68000;
  if(s.equals("missed_callback_self"))return 0xff2869ba;
  if(s.equals("BUSY"))return 0xffc2573c;
  if(s.equals("answered") || s.equals("ANSWERED"))return 0xff177d58;
  return teal;
 }
 // Card backgrounds match the semantic status colors of the iOS app.
 int statusBackground(String state){
  if(dark){
   if(state.equals("missed_no_callback") || state.equals("miss_night_1"))return 0xff38252d;
   if(state.equals("missed_callback_answered") || state.equals("miss_night_2") || state.equals("missed_callback_self"))return state.equals("missed_callback_self")?0xff233349:0xff28392b;
   if(state.equals("missed_callback_noanswer") || state.equals("NO ANSWER"))return 0xff3c3321;
   if(state.equals("BUSY"))return 0xff3a2b26;
   return panel();
  }
  if(state.equals("missed_no_callback") || state.equals("miss_night_1"))return 0xffffe8eb;
  if(state.equals("missed_callback_answered") || state.equals("miss_night_2"))return 0xfff0f7e9;
  if(state.equals("missed_callback_self"))return 0xffedf3ff;
  if(state.equals("missed_callback_noanswer") || state.equals("NO ANSWER"))return 0xfffff6dd;
  if(state.equals("BUSY"))return 0xffffede5;
  return panel();
 }
 int statusBorder(String state){
  if(state.equals("missed_no_callback") || state.equals("miss_night_1"))return dark?0xff78414a:0xffefd6da;
  if(state.equals("missed_callback_answered") || state.equals("miss_night_2"))return dark?0xff506b46:0xffd9e8cc;
  if(state.equals("missed_callback_self"))return dark?0xff4a668a:0xffd7e3fb;
  if(state.equals("missed_callback_noanswer") || state.equals("NO ANSWER"))return dark?0xff78633b:0xffefdfb3;
  return dark?0xff3f4b55:0xffdedee2;
 }
 void renderChips(){chips.removeAllViews();String[] ids=section.equals("incoming")?incoming:outgoing,names=section.equals("incoming")?incomingNames:outgoingNames;for(int i=0;i<ids.length;i++){final String id=ids[i];int fg=id.equals("missed_no_callback")?0xffd13d48:id.equals("missed_callback_answered")?0xff418322:id.equals("NO ANSWER")?0xffb47b00:id.equals("BUSY")?0xffc2573c:0xff454545;int fill=id.equals("missed_no_callback")?0xffffe7e9:id.equals("missed_callback_answered")?0xffe7f3e2:id.equals("NO ANSWER")?0xfffff2cc:id.equals("BUSY")?0xffffe8e0:Color.WHITE;boolean selected=status.equals(id);TextView chip=text(names[i],12,selected);chip.setTextColor(selected?Color.WHITE:fg);GradientDrawable back=shape(selected?teal:fill,24);if(!selected)back.setStroke(dp(1),0xffdcdce0);chip.setBackground(back);chip.setGravity(Gravity.CENTER);chip.setPadding(dp(10),dp(6),dp(10),dp(6));LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(-2,-2);lp.setMargins(dp(2),0,dp(2),0);chips.addView(chip,lp);chip.setOnClickListener(v->{if(status.equals(id))return;status=id;renderChips();reload();});}}
 void reload(){int gen=++generation;offset=0;total=0;calls.clear();currentKpi=new JSONObject();renderList();fetch(gen,false);}
 void fetch(int gen,boolean more){if(loading&&more)return;loading=true;if(!more){list.removeAllViews();add(list,text("Загружаем звонки…",15,false));}String sec=section,stat=status,day=date;int off=more?offset:0;executor.execute(()->{try{String query="type="+enc(sec)+"&date_from="+enc(day)+"&date_to="+enc(day)+"&limit=100&offset="+off+(stat.isEmpty()?"":"&status="+enc(stat));JSONObject result=request(address,token,query);JSONArray arr=result.getJSONArray("calls");JSONObject pagination=result.optJSONObject("pagination"),kpi=result.optJSONObject("kpi");ArrayList<JSONObject> page=new ArrayList<>();for(int i=0;i<arr.length();i++)page.add(arr.getJSONObject(i));runOnUiThread(()->{if(gen!=generation)return;loading=false;calls.addAll(page);offset=calls.size();total=pagination==null?calls.size():pagination.optInt("total",calls.size());currentKpi=kpi==null?new JSONObject():kpi;renderList();});}catch(Exception e){runOnUiThread(()->{if(gen!=generation)return;loading=false;if(e.getMessage()!=null&&e.getMessage().contains("401")){getPreferences(0).edit().putBoolean("connected",false).apply();saveToken("");token="";login();return;}renderList();alert(e.getMessage());});}});}
 void renderList(){kpis.removeAllViews();if(status.isEmpty()){tile("Всего",currentKpi.optString("total_calls","0"),teal);tile(section.equals("incoming")?"Пропущенные":"Не дозвонились",currentKpi.optString(section.equals("incoming")?"missed_no_callback":"noanswer","0"),section.equals("incoming")?0xffb52d39:0xffae7900);}else tile("Всего",""+total,statusColor(status));journalLabel.setText(section.equals("incoming")?"Журнал входящих":"Журнал исходящих");countLabel.setText(calls.size()+" из "+total);list.removeAllViews();if(calls.isEmpty()){add(list,text(loading?"Загружаем звонки…":"Звонков нет за выбранную дату",15,false));return;}for(JSONObject call:calls)card(call);if(calls.size()<total){Button more=new Button(this);more.setText(loading?"Загрузка…":"Загрузить ещё");more.setEnabled(!loading);add(list,more);more.setOnClickListener(v->fetch(generation,true));}}
 void tile(String label,String value,int color){LinearLayout t=vertical();t.setPadding(dp(12),dp(6),dp(10),dp(5));int background=color==0xffb52d39?0xffffe8e9:color==0xffae7900?0xfffff2cd:panel();t.setBackground(shape(background,14));TextView name=text(label,11,false),num=text(value,20,true);name.setTextColor(0xff8b8b91);num.setTextColor(color==teal?0xff424242:color);add(t,name);space(t,1);add(t,num);LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(0,dp(57),1);lp.setMargins(dp(4),0,dp(4),0);kpis.addView(t,lp);}
 String get(JSONObject j,String k){
  if(j==null || j.isNull(k))return "";
  String value=j.optString(k,"").trim();
  return value.equalsIgnoreCase("null")?"":value;
 }
 String dash(String value){return value==null || value.trim().isEmpty() || value.trim().equalsIgnoreCase("null")?"—":value;}String time(JSONObject j){String s=get(j,"call_time");return s.length()>16?s.substring(0,16):s;}String duration(int sec){return String.format(Locale.US,"%02d:%02d",sec/60,sec%60);}String nameFor(String s){String[] ids=section.equals("incoming")?incoming:outgoing,names=section.equals("incoming")?incomingNames:outgoingNames;for(int i=0;i<ids.length;i++)if(ids[i].equals(s))return names[i];return s;}
 void card(JSONObject j){
 boolean inc=section.equals("incoming");String state=get(j,"status");
 LinearLayout c=vertical();c.setPadding(dp(10),dp(6),dp(10),dp(6));GradientDrawable back=shape(statusBackground(state),13);back.setStroke(dp(1),statusBorder(state));c.setBackground(back);LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(-1,-2);lp.setMargins(0,0,0,dp(5));list.addView(c,lp);
 LinearLayout top=horizontal();add(c,top);TextView when=text(prettyTime(time(j)),12,true);when.setSingleLine(true);when.setEllipsize(android.text.TextUtils.TruncateAt.END);when.setTextColor(foreground());top.addView(when,new LinearLayout.LayoutParams(0,-2,1));TextView st=text(nameFor(state),10,true);st.setTextColor(statusColor(state));st.setSingleLine(true);add(top,st);
 String clientPhone=get(j,"client_number");
 if(!clientPhone.isEmpty()){
  TextView callButton=text("☎↗",18,true);
  callButton.setTextColor(foreground());
  callButton.setGravity(Gravity.CENTER);
  LinearLayout.LayoutParams cp=new LinearLayout.LayoutParams(dp(32),dp(28));
  cp.setMargins(dp(5),0,0,0);
  top.addView(callButton,cp);
  callButton.setContentDescription("Позвонить клиенту " + clientPhone);
  callButton.setOnClickListener(v->showCallDialog(j));
 }
 boolean hasTalkDuration=j.optInt("talk_seconds",0)>0;boolean record=hasTalkDuration&&(inc?!get(j,"callid").isEmpty():!get(j,"recording_file").isEmpty()&&j.optInt("id",0)>0);if(record){TextView play=text("▶",13,true);play.setGravity(Gravity.CENTER);play.setTextColor(Color.WHITE);play.setBackground(shape(Color.BLACK,30));LinearLayout.LayoutParams pp=new LinearLayout.LayoutParams(dp(25),dp(25));pp.setMargins(dp(8),0,0,0);top.addView(play,pp);play.setOnClickListener(v->playRecording(j,null));}
 space(c,1);
 TextView clientLabel=text("КЛИЕНТ",9,false);clientLabel.setTextColor(0xff939398);add(c,clientLabel);
 String number=get(j,"client_number");String name=inc?nonempty(get(j,"client_name"),number):number;
 TextView clientName=text(name.isEmpty()?"—":name,14,true);clientName.setTextColor(foreground());clientName.setSingleLine(true);clientName.setEllipsize(android.text.TextUtils.TruncateAt.END);add(c,clientName);if(!number.isEmpty()){clientName.setTextColor(teal);clientName.setOnClickListener(v->showCallDialog(j));}
 // The iOS design intentionally shows BOTH fields even when client_name == client_number.
 if(inc&&!number.isEmpty()){TextView clientNumber=text(number,10,false);clientNumber.setTextColor(0xff919196);clientNumber.setSingleLine(true);clientNumber.setTextColor(teal);add(c,clientNumber);clientNumber.setOnClickListener(v->showCallDialog(j));}
 space(c,2);
 LinearLayout columns=horizontal();add(c,columns);LinearLayout left=vertical(),right=vertical();columns.addView(left,new LinearLayout.LayoutParams(0,-2,1));columns.addView(right,new LinearLayout.LayoutParams(0,-2,1));
 TextView qLabel=text(inc?"ОЧЕРЕДЬ":"АГЕНТ",9,false);qLabel.setTextColor(0xff939398);add(left,qLabel);
 TextView qValue=text(dash(inc?nonempty(get(j,"queue_label"),get(j,"queue_number")):get(j,"agent_number")),11,true);qValue.setSingleLine(true);qValue.setEllipsize(android.text.TextUtils.TruncateAt.END);add(left,qValue);
 {TextView aLabel=text(inc?"АГЕНТ":"НОМЕР",9,false);aLabel.setTextColor(0xff939398);add(right,aLabel);TextView aValue=text(dash(inc?get(j,"agent_ext"):get(j,"did_number")),11,true);aValue.setSingleLine(true);add(right,aValue);}
 if(inc){TextView did=text(nonempty(get(j,"did_label"),get(j,"did_number"))+(!get(j,"did_number").isEmpty()?" · "+get(j,"did_number"):""),9,false);did.setTextColor(0xffb0b0b5);did.setSingleLine(true);did.setEllipsize(android.text.TextUtils.TruncateAt.END);add(c,did);}
 LinearLayout durations=horizontal();add(c,durations);TextView wait=text("⌛ Ожидание "+duration(j.optInt("wait_seconds")),9,false);wait.setTextColor(0xff85858a);durations.addView(wait,new LinearLayout.LayoutParams(0,-2,1));TextView talk=text("◷ Разговор "+duration(j.optInt("talk_seconds")),9,false);talk.setTextColor(0xff85858a);add(durations,talk);
 }
 void field(LinearLayout parent,String label,String value){TextView l=text(label,11,false);l.setTextColor(0xff8b8b91);add(parent,l);TextView v=text(value.isEmpty()?"—":value,14,false);v.setTextColor(foreground());add(parent,v);}
 String prettyTime(String s){try{Date d=new SimpleDateFormat("yyyy-MM-dd HH:mm",Locale.US).parse(s);return new SimpleDateFormat("d MMM yyyy, HH:mm",new Locale("ru")).format(d);}catch(Exception e){return s;}}
 String nonempty(String a,String b){return a.isEmpty()?b:a;}void alert(String message){new AlertDialog.Builder(this).setMessage(message==null?"Ошибка":message).setPositiveButton("OK",null).show();}
 static String enc(String s)throws Exception{return java.net.URLEncoder.encode(s,"UTF-8");}
 JSONObject request(String base,String auth,String query)throws Exception{return request(base,auth,query,true);}
 JSONObject request(String base,String auth,String query,boolean requireCalls)throws Exception{String target=apiUrl(base);HttpURLConnection con=(HttpURLConnection)new URL(target+"?"+query).openConnection();con.setConnectTimeout(25000);con.setReadTimeout(25000);con.setRequestProperty("Accept","application/json");if(!auth.isEmpty())con.setRequestProperty("Authorization","Bearer "+auth);try{int code=con.getResponseCode();InputStream in=code>=200&&code<300?con.getInputStream():con.getErrorStream();String body=readString(in);if(code<200||code>=300)throw new Exception("HTTP "+code+": "+body);JSONObject json=new JSONObject(body);if(!json.optBoolean("ok")||(requireCalls&&!json.has("calls")))throw new Exception("Некорректный JSON API");return json;}finally{con.disconnect();}}
 String readString(InputStream in)throws Exception{if(in==null)return "";ByteArrayOutputStream out=new ByteArrayOutputStream();byte[] buf=new byte[8192];int n;while((n=in.read(buf))!=-1)out.write(buf,0,n);in.close();return out.toString("UTF-8");}
 void playRecording(JSONObject j,Button button){if(button!=null)button.setEnabled(false);String id=section.equals("incoming")?get(j,"callid"):get(j,"id"),key=section.equals("incoming")?"uniqueid":"outgoing_id";String base=address,auth=token;executor.execute(()->{File file=null;try{URL url=new URL(base);String path=url.getPath();String dir=path.endsWith("/")?path:path+"/";String target=url.getProtocol()+"://"+url.getAuthority()+dir+"recordings.php?"+key+"="+enc(id);HttpURLConnection con=(HttpURLConnection)new URL(target).openConnection();con.setConnectTimeout(25000);con.setReadTimeout(180000);con.setRequestProperty("Accept","audio/mpeg, audio/*;q=0.9, */*;q=0.8");if(!auth.isEmpty())con.setRequestProperty("Authorization","Bearer "+auth);try{int code=con.getResponseCode();if(code<200||code>=300)throw new Exception("HTTP "+code+": "+readString(con.getErrorStream()));file=File.createTempFile("qstat-recording-",".mp3",getCacheDir());try(InputStream in=con.getInputStream();FileOutputStream out=new FileOutputStream(file)){byte[] buf=new byte[8192];int n;while((n=in.read(buf))!=-1)out.write(buf,0,n);}}finally{con.disconnect();}File f=file;runOnUiThread(()->{if(button!=null)button.setEnabled(true);showPlayer(f,j);});}catch(Exception e){if(file!=null)file.delete();runOnUiThread(()->{if(button!=null)button.setEnabled(true);alert("Не удалось открыть запись: "+e.getMessage());});}});}
 void showPlayer(File file,JSONObject call){MediaPlayer player=new MediaPlayer();try{player.setDataSource(file.getAbsolutePath());player.prepare();}catch(Exception e){file.delete();alert(e.getMessage());return;}
 LinearLayout content=vertical();content.setPadding(dp(24),dp(22),dp(24),dp(30));content.setBackgroundColor(panel());TextView heading=text("Запись звонка",21,true);heading.setGravity(Gravity.CENTER);add(content,heading);space(content,25);TextView waveform=text("▂▅█▆▃",36,true);waveform.setTextColor(teal);waveform.setGravity(Gravity.CENTER);add(content,waveform);space(content,18);TextView callTitle=text("Запись звонка · "+prettyTime(time(call)),17,true);callTitle.setGravity(Gravity.CENTER);add(content,callTitle);space(content,24);
 SeekBar seek=new SeekBar(this);seek.setMax(Math.max(1,player.getDuration()));seek.setProgressTintList(android.content.res.ColorStateList.valueOf(teal));seek.setThumbTintList(android.content.res.ColorStateList.valueOf(teal));add(content,seek);LinearLayout timing=horizontal();add(content,timing);TextView elapsed=text("00:00",13,false),remaining=text(duration(player.getDuration()/1000),13,false);elapsed.setTextColor(0xff8b8b91);remaining.setTextColor(0xff8b8b91);timing.addView(elapsed,new LinearLayout.LayoutParams(0,-2,1));remaining.setGravity(Gravity.RIGHT);timing.addView(remaining,new LinearLayout.LayoutParams(0,-2,1));space(content,25);
 TextView toggle=text("▶ Воспроизвести",19,true);toggle.setGravity(Gravity.CENTER);toggle.setTextColor(Color.WHITE);toggle.setBackground(shape(teal,13));add(content,toggle);toggle.setPadding(dp(10),dp(17),dp(10),dp(17));space(content,20);TextView hint=text("Можно перемещать ползунок, чтобы перейти к нужному месту записи.",13,false);hint.setTextColor(0xff929298);hint.setGravity(Gravity.CENTER);add(content,hint);space(content,12);Button callback=new Button(this);callback.setText("☎ Позвонить клиенту");add(content,callback);callback.setOnClickListener(v->showCallDialog(call));
 Handler handler=new Handler(Looper.getMainLooper());Runnable tick=new Runnable(){public void run(){try{int ms=player.getCurrentPosition();seek.setProgress(ms);elapsed.setText(duration(ms/1000));handler.postDelayed(this,300);}catch(Exception ignored){}}};seek.setOnSeekBarChangeListener(new SeekBar.OnSeekBarChangeListener(){public void onProgressChanged(SeekBar b,int value,boolean user){if(user)player.seekTo(value);}public void onStartTrackingTouch(SeekBar b){}public void onStopTrackingTouch(SeekBar b){}});toggle.setOnClickListener(v->{if(player.isPlaying()){player.pause();toggle.setText("▶ Воспроизвести");}else{player.start();toggle.setText("Ⅱ Пауза");}});player.setOnCompletionListener(p->toggle.setText("▶ Воспроизвести"));AlertDialog dialog=new AlertDialog.Builder(this).setView(content).setPositiveButton("Готово",(a,b)->{}).create();dialog.setOnDismissListener(d->{handler.removeCallbacks(tick);player.release();file.delete();});dialog.show();handler.post(tick);}
 void showCallDialog(JSONObject call){String number=get(call,"client_number");String name=nonempty(get(call,"client_name"),number);if(number.isEmpty()){alert("Номер клиента отсутствует");return;}if(agents.length()==0){alert("Список агентов пока не загружен. Проверьте API.");syncConnected();return;}LinearLayout content=vertical();pad(content,18);add(content,text(name,18,true));add(content,text(number,15,false));space(content,12);AlertDialog dialog=new AlertDialog.Builder(this).setTitle("Позвонить клиенту").setView(content).setNegativeButton("Отмена",null).create();for(int i=0;i<agents.length();i++){JSONObject agent=agents.optJSONObject(i);if(agent==null)continue;String ext=agent.optString("number","");String label=agent.optString("name",ext);if(ext.isEmpty())continue;Button b=new Button(this);b.setText(label+" · "+ext);add(content,b);b.setOnClickListener(v->{b.setEnabled(false);String base=address,auth=token;executor.execute(()->{try{JSONObject payload=new JSONObject();payload.put("from",ext);payload.put("to",number);JSONObject response=postJson(siblingUrl(base,"call.php"),"",auth,payload);if(!response.optBoolean("ok"))throw new Exception(response.optString("error","Ошибка вызова"));runOnUiThread(()->{dialog.dismiss();Toast.makeText(this,"Запрос на звонок принят",Toast.LENGTH_SHORT).show();});}catch(Exception e){runOnUiThread(()->{b.setEnabled(true);alert("Не удалось вызвать: "+e.getMessage());});}});});}dialog.show();}
 String siblingUrl(String base,String file)throws Exception{URL u=new URL(base);return normalizeBase(base)+"/"+file;}
 JSONObject postJson(String base,String query,String auth,JSONObject data)throws Exception{String url=(query.isEmpty()?base:apiUrl(base)+"?"+query);HttpURLConnection con=(HttpURLConnection)new URL(url).openConnection();con.setRequestMethod("POST");con.setConnectTimeout(15000);con.setReadTimeout(35000);con.setDoOutput(true);con.setRequestProperty("Content-Type","application/json; charset=utf-8");con.setRequestProperty("Accept","application/json");if(!auth.isEmpty())con.setRequestProperty("Authorization","Bearer "+auth);try{byte[] bytes=data.toString().getBytes("UTF-8");try(OutputStream out=con.getOutputStream()){out.write(bytes);}int code=con.getResponseCode();String body=readString(code>=200&&code<300?con.getInputStream():con.getErrorStream());if(code<200||code>=300)throw new Exception("HTTP "+code+": "+body);return new JSONObject(body);}finally{con.disconnect();}}
 void saveToken(String t){try{KeyStore ks=KeyStore.getInstance("AndroidKeyStore");ks.load(null);if(!ks.containsAlias("qstat-key")){KeyGenerator kg=KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore");kg.init(new KeyGenParameterSpec.Builder("qstat-key",KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build());kg.generateKey();}SecretKey key=(SecretKey)ks.getKey("qstat-key",null);Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.ENCRYPT_MODE,key);byte[] ciphertext=cipher.doFinal(t.getBytes("UTF-8"));getPreferences(0).edit().putString("token_data",android.util.Base64.encodeToString(ciphertext,0)).putString("token_iv",android.util.Base64.encodeToString(cipher.getIV(),0)).apply();}catch(Exception e){alert("Не удалось сохранить токен: "+e.getMessage());}}
 String loadToken(){try{String data=getPreferences(0).getString("token_data","");if(data.isEmpty())return "";KeyStore ks=KeyStore.getInstance("AndroidKeyStore");ks.load(null);SecretKey key=(SecretKey)ks.getKey("qstat-key",null);Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.DECRYPT_MODE,key,new GCMParameterSpec(128,android.util.Base64.decode(getPreferences(0).getString("token_iv",""),0)));return new String(cipher.doFinal(android.util.Base64.decode(data,0)),"UTF-8");}catch(Exception e){return "";}}
 @Override protected void onDestroy(){generation++;executor.shutdownNow();super.onDestroy();}
}
