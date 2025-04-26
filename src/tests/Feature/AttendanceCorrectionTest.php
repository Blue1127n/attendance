<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function createUser()
    {
        return User::forceCreate([
            'last_name' => '田中',
            'first_name' => '一郎',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);
    }

    //出勤時間が退勤時間より後になっている場合、エラーメッセージが表示される
    public function testClockTimesShowsError()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 勤怠データ作成（今日の日付で）
        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);

        //不正な出勤・退勤時間（出勤が退勤より後）
        //POSTデータに clock_in, clock_out, remarks, breaks を渡す
        $postData = [
            'clock_in' => '19:00',
            'clock_out' => '18:00',
            'remarks' => 'テストのため不正な時間',
            'breaks' => [], // 休憩なし
        ];

        //修正申請をPOST（バリデーションが走る）POSTで送ること
        $response = $this->post("/attendance/{$attendance->id}/correction", $postData);

        //バリデーションエラーをチェック エラーセッションに clock_in が含まれることをチェック
        $response->assertSessionHasErrors(['clock_in']);
        $this->assertEquals('出勤時間もしくは退勤時間が不適切な値です', session('errors')->first('clock_in'));
    }

    //休憩開始時間が退勤時間より後になっている場合、エラーメッセージが表示される
    public function testBreakStartShowsError()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);

        //テストの目的は「POSTされた休憩データが不正な場合にバリデーションエラーになるか」だから
        //もともとDBに休憩データがあるかどうかは関係ない POSTで送るデータの中で「休憩開始が退勤より後」になっていればOK
        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'remarks' => 'テストのため不正な時間',
            'breaks' => [
                ['start' => '19:00', 'end' => '20:00']
            ]
        ];
        //'breaks' => [[ ]] の意味 これは 「複数の休憩がある場合」に備えた構造

        $response = $this->post("/attendance/{$attendance->id}/correction", $postData);

        $response->assertSessionHasErrors(['breaks.0.start']); //'breaks.0.end' というキーでバリデーションされる（複数対応なので配列形式）
        $this->assertEquals('休憩時間が勤務時間外です', session('errors')->first('breaks.0.start'));
    }

    //休憩終了時間が退勤時間より後になっている場合、エラーメッセージが表示される
    public function testBreakEndShowsError()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'remarks' => 'テストのため不正な時間',
            'breaks' => [
                ['start' => '17:00', 'end' => '19:00']
            ]
        ];

        $response = $this->post("/attendance/{$attendance->id}/correction", $postData);

        $response->assertSessionHasErrors(['breaks.0.start']);
        $this->assertEquals('休憩時間が勤務時間外です', session('errors')->first('breaks.0.start'));
    }

    //備考欄が未入力の場合のエラーメッセージが表示される
    public function testRemarkShowsError()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '19:00',
            'remarks' => '',
        ];

        $response = $this->post("/attendance/{$attendance->id}/correction", $postData);

        $response->assertSessionHasErrors(['remarks']);
        $this->assertEquals('備考を記入してください', session('errors')->first('remarks'));
    }

    //修正申請処理が実行される
    public function testCorrectionSent()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '19:00',
            'remarks' => '退勤時間の修正',
            'breaks' => [], //休憩がない場合でも必要（バリデーション通すため）
        ];

        $response = $this->post("/attendance/{$attendance->id}/correction", $postData);

        //リクエスト（POST）が正しく処理されて、リダイレクトされたことを確認するため
        //ユーザーが「修正申請」フォームで入力→POST /attendance/{id}/correction に送信する→
        //バリデーションが通ったら return redirect()->route(...); される（リダイレクト）→
        //このとき Laravel は自動的に HTTPステータスコード 302（Found） を返すから
        //このPOSTリクエストはバリデーションも含めて正常に処理された
        //そして、処理後にリダイレクトされた（成功！）を確認するため
        $response->assertStatus(302); // 通常リダイレクトされる

        //attendance_requests テーブルに保存されたか確認
        //$this->assertDatabaseHas() を使うことで「本当に保存されたか？」をチェック
        $this->assertDatabaseHas('attendance_requests', [
            'user_id' => $user->id,
            'attendance_id' => $attendance->id,
            'requested_clock_out' => '19:00:00',
            'remarks' => '退勤時間の修正',
            'status' => '承認待ち',
    ]);
    }

    //「承認待ち」にログインユーザーが行った申請が全て表示されていること
    public function testPendingShown()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '19:00',
            'remarks' => '退勤時間の修正',
            'breaks' => [],
        ];

        $response = $this->post("/attendance/{$attendance->id}/correction", $postData);

        //「申請一覧」画面にアクセスして、内容が表示されていることを確認
        $response = $this->get('/stamp_correction_request/list');

        $response->assertStatus(200);
        $response->assertSee('退勤時間の修正'); // 備考が表示されているか
        $response->assertSee('承認待ち');         // ステータスが表示されているか
    }

    //「承認済み」に管理者が承認した修正申請が全て表示されている
    public function testApprovedShown()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '19:00',
            'remarks' => '退勤時間の修正',
            'breaks' => [],
        ];

        $response = $this->post("/attendance/{$attendance->id}/correction", $postData);

        //修正申請が作られているはずなので取得
        $request = \App\Models\AttendanceRequest::first();
        //\App\Models\AttendanceRequest AttendanceRequest モデルを指してる
        //app/Models/AttendanceRequest.php にあるモデルクラスを使うよってこと
        //first()データベースに対して 「最初の1件だけ」取り出す メソッド
        //「attendance_requests テーブルに保存されている最初の申請データ（1件）を取得して、$request に入れてる」意味
        //$request = \App\Models\AttendanceRequest::where('user_id', $user->id)->where('attendance_id', $attendance->id)->first();でもok

        // 管理者が承認したことにする
        $request->update(['status' => '承認済み']);

        // 承認済み一覧に表示されるかを確認
        $response = $this->get('/stamp_correction_request/list');

        $response->assertStatus(200);
        $response->assertSee('承認済み');
        $response->assertSee('退勤時間の修正'); // 備考などで確認
    }

    //各申請の「詳細」を押下すると申請詳細画面に遷移する
    public function testRequestDetailShown()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '19:00',
            'remarks' => '退勤時間の修正',
            'breaks' => [],
        ];

        $this->post("/attendance/{$attendance->id}/correction", $postData);

        //申請一覧にアクセスして「詳細」ページに遷移
        $request = \App\Models\AttendanceRequest::first();
        $response = $this->get("/admin/stamp_correction_request/approve/{$request->id}");

        $response->assertStatus(200);
        $response->assertSee('退勤時間の修正');
    }
}

