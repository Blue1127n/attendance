<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class AdminAttendanceDetailTest extends TestCase
{
    use RefreshDatabase;

    public function createUser()
    {
        return User::forceCreate([
            'last_name' => '田中',
            'first_name' => '次郎',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);
    }

    private function createAttendances($user)
    {
        return Attendance::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => '退勤済',
        ]);
    }
    //return するようにしたら$attendance->idが使用できる

    //勤怠詳細画面に表示されるデータが選択したものになっている
    public function testDetailShown()
    {
        $user = $this->createUser();
        $attendance = $this->createAttendances($user); //勤怠データ作成＋ID取得　return を使って$attendance->id を受け取る
        $this->actingAs($user);

        //正しいルートは /admin/attendance/{id} ですので、該当の勤怠IDを使う必要がある
        $response = $this->get('admin/attendance/' . $attendance->id); //勤怠詳細ページにアクセス

        $response->assertStatus(200);
        $response->assertSee('田中'); //assertSee('田中 次郎') が失敗している理由 <div class="value">田中&nbsp;&nbsp;&nbsp;次郎</div>
        $response->assertSee('次郎'); //実際には "田中 次郎"（半角スペース3つ）という形で &nbsp;&nbsp;&nbsp; が HTML に含まれているから
        $response->assertSee('09:00');
        $response->assertSee('18:00');
    }

    //出勤時間が退勤時間より後になっている場合、エラーメッセージが表示される
    public function testClockTimesShowsError()
    {
        $user = $this->createUser();
        $attendance = $this->createAttendances($user);
        $this->actingAs($user);

        //不正な出勤・退勤時間（出勤が退勤より後）
        //POSTデータに clock_in, clock_out, remarks, breaks を渡す
        $postData = [
            'clock_in' => '19:00',
            'clock_out' => '18:00',
            'remarks' => 'テストのため不正な時間',
            'breaks' => [], // 休憩なし
        ];

        //修正申請をPOST（バリデーションが走る）POSTで送ること
        $response = $this->post("/admin/attendance/{$attendance->id}/update", $postData);

        //バリデーションエラーをチェック エラーセッションに clock_in が含まれることをチェック
        $response->assertSessionHasErrors(['clock_in']);
        $this->assertEquals('出勤時間もしくは退勤時間が不適切な値です', session('errors')->first('clock_in'));
    }

    //休憩開始時間が退勤時間より後になっている場合、エラーメッセージが表示される
    public function testBreakStartShowsError()
    {
        $user = $this->createUser();
        $attendance = $this->createAttendances($user);
        $this->actingAs($user);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'remarks' => 'テストのため不正な時間',
            'breaks' => [
                ['start' => '19:00', 'end' => '20:00']
            ]
        ];
        //'breaks' => [[ ]] の意味 これは 「複数の休憩がある場合」に備えた構造

        $response = $this->post("/admin/attendance/{$attendance->id}/update", $postData);

        $response->assertSessionHasErrors(['breaks.0.start']); //'breaks.0.end' というキーでバリデーションされる（複数対応なので配列形式）
        $this->assertEquals('休憩時間が勤務時間外です', session('errors')->first('breaks.0.start'));
    }

    //休憩終了時間が退勤時間より後になっている場合、エラーメッセージが表示される
    public function testBreakEndShowsError()
    {
        $user = $this->createUser();
        $attendance = $this->createAttendances($user);
        $this->actingAs($user);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'remarks' => 'テストのため不正な時間',
            'breaks' => [
                ['start' => '17:00', 'end' => '19:00']
            ]
        ];

        $response = $this->post("/admin/attendance/{$attendance->id}/update", $postData);

        $response->assertSessionHasErrors(['breaks.0.start']);
        $this->assertEquals('休憩時間が勤務時間外です', session('errors')->first('breaks.0.start'));
    }

    //備考欄が未入力の場合のエラーメッセージが表示される
    public function testRemarkShowsError()
    {
        $user = $this->createUser();
        $attendance = $this->createAttendances($user);
        $this->actingAs($user);

        $postData = [
            'clock_in' => '09:00',
            'clock_out' => '19:00',
            'remarks' => '',
        ];

        $response = $this->post("/admin/attendance/{$attendance->id}/update", $postData);

        $response->assertSessionHasErrors(['remarks']);
        $this->assertEquals('備考を記入してください', session('errors')->first('remarks'));
    }
}
