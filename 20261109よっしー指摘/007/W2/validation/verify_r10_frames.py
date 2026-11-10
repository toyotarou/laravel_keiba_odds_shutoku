"""Independent response-boundary corpus. Expected JSON values are supplied before framing."""
from base64 import b64encode,b64decode
from decimal import Decimal
from pathlib import Path
import json,random,subprocess

ROOT=Path(__file__).resolve().parents[1]
BEGIN=b'<<<BAGANRIKI_V041_R8_BEGIN>>>'
END=b'<<<BAGANRIKI_V041_R8_END>>>'

def decoded(raw):
    def pairs(items):
        obj={}
        for k,v in items:
            if k in obj:raise ValueError('duplicate')
            obj[k]=v
        return obj
    def invalid(token):raise ValueError(token)
    return json.loads(raw,parse_int=Decimal,parse_float=Decimal,parse_constant=invalid,object_pairs_hook=pairs)

def run(php,backend,seed):
    rng=random.Random(seed);cases=[]
    def case(label,raw,body=None):cases.append((label,raw,body))
    def framed(body,nl=b'\n',tail=b''):
        return BEGIN+nl+body+nl+END+tail
    markers=[BEGIN.decode(),END.decode(),BEGIN.decode()+'\n'+END.decode(),END.decode()+BEGIN.decode()]
    for i in range(64):
        words=['日本語','馬番1','\r\n','"','\\','🧅','\u2028','\u2029','\0','\x0B']+markers
        rng.shuffle(words)
        obj={'explanation':' '.join(words),'facts':[{'value':'1.00000000000000000001'}, {}, [], None, True, False],
             'evidence':{'0':markers[i%4],'nested':{'marker':markers[(i+1)%4]}}}
        body=json.dumps(obj,ensure_ascii=(i%2==0),indent=2 if i%3==0 else None,separators=None if i%3==0 else (',',':')).encode()
        nl=b'\r\n' if i%2 else b'\n';tail=[b'',b'\n',b'\r\n',b'\n\r\n'][i%4]
        case('text-'+str(i),framed(body,nl,tail),body)
    for number in [b'0',b'-0',b'1.0',b'1e0',b'1.999999999999999999999',b'123456789012345678901234567890',b'1e-308',b'1e308',b'9'*128]:
        body=b'{"n":'+number+b',"s":"'+number+b'","empty_object":{},"empty_array":[]}'
        case('exact-number-'+number[:30].decode(),framed(body),body)
    body=b'{"normal":"'+BEGIN+b' / '+END+b'","number":1}'
    for left in [b'',b' ',b'\t',b'\r',b'\n',b' \r\n\t']:
        for right in [b'',b' ',b'\t',b'\r',b'\n',b' \n\r\t']:
            padded=left+body+right;case('json-whitespace',framed(padded),padded)
    simple=b'{"a":1}'
    for c in range(32):
        if c in (9,10,13):continue
        byte=bytes([c])
        for altered in [byte+simple,simple+byte,b'{'+byte+b'"a":1}',b'{"a":'+byte+b'1}',b'{"a":1'+byte+b'}']:
            case('raw-control-'+str(c),framed(altered))
        escaped=json.dumps({'text':chr(c)}).encode();case('escaped-control-'+str(c),framed(escaped),escaped)
    for wrong in [b'',b'[]',b'1',b'"text"',b'true',b'null',b'{}{}',b'{"a":1,"a":2}',b'{"a":1,"\\u0061":2}',
                  b'{"x":NaN}',b'{"x":Infinity}',b'{"x":1e309}',b'{"x":'+b'9'*129+b'}',b'{"x":"\\ud800"}',
                  b'{"x":"\\udc00"}',b'{"x":"\xff"}',b'\xef\xbb\xbf{}',b'{"x":1,}',b'{"x":"unescaped\ntext"}',
                  b'{"x":"'+END+b'"',b'{"x":'+END+b'}',b'{"x":'+BEGIN+b'}',b'{}\xc2\xa0',b'\xc2\xa0{}']:
        case('invalid-json',framed(wrong))
    good=framed(simple)
    for wrong in [simple,BEGIN+simple+END,b'prefix\n'+good,good+b'\ntext',b'```json\n'+good+b'\n```',good+b'\n'+good,
                  good+b'\n'+END,BEGIN+b'\n'+good,b' '+good,good+b' ',BEGIN+b'\r\r\n'+simple+b'\n'+END,
                  good+b'\r',good+b'\n\0',b'\n'+good,BEGIN+b'\n'+simple+b'\n'+END+b'\n\r',
                  BEGIN+b'\r'+simple+b'\r'+END,BEGIN+b'\n'+simple+b'\n'+END+b'\x0b']:
        case('invalid-outer-frame',wrong)
    # Boundary is byte length of the actual JSON body, including its whitespace.
    for size in [4194303,4194304,4194305]:
        large=b'{"x":"'+b'a'*(size-8)+b'"}';assert len(large)==size
        case('json-size-'+str(size),framed(large),large if size<=4194304 else None)
    case('whitespace-counts-toward-body-cap',framed(b' '*4194303+b'{}'))
    case('outer-cap-cannot-be-hidden-by-newlines',framed(simple,tail=b'\n'*4194400))
    data=''.join(json.dumps({'raw':b64encode(raw).decode()})+'\n' for _,raw,_ in cases)
    p=subprocess.run([str(php),str(ROOT/'validation/r10_frame_probe.php'),str(backend)],input=data,text=True,capture_output=True,check=True)
    rows=p.stdout.splitlines();assert len(rows)==len(cases),(len(rows),len(cases),p.stderr[:400])
    positives=0
    for (label,raw,body),line in zip(cases,rows):
        actual=json.loads(line);assert actual['ok']==(body is not None),(label,actual,raw[:90])
        if body is not None:
            positives+=1
            assert decoded(b64decode(actual['json']))==decoded(body),(label,'value/type changed')
            assert actual['has_numeric_map'],label
        else:assert actual['code'] in ('W2_RESPONSE_FRAME','W2_RESPONSE_JSON'),(label,actual)
    print(f'PASS R10 independent boundary corpus: {len(cases)} cases; {positives} exact typed JSON payloads kept, {len(cases)-positives} malformed frames/JSON rejected (seed {seed}).')
