(function (global) {
    'use strict';

    var FILES = 'abcdefgh';
    var PROMOTION_PIECES = ['q', 'r', 'b', 'n'];
    var PIECE_GLYPHS = {
        wp: '♙',
        wn: '♘',
        wb: '♗',
        wr: '♖',
        wq: '♕',
        wk: '♔',
        bp: '♟',
        bn: '♞',
        bb: '♝',
        br: '♜',
        bq: '♛',
        bk: '♚'
    };
    var PIECE_NAMES = {
        p: '',
        n: 'N',
        b: 'B',
        r: 'R',
        q: 'Q',
        k: 'K'
    };
    var ALLOWED_PIECES = Object.freeze({
        wp: true,
        wn: true,
        wb: true,
        wr: true,
        wq: true,
        wk: true,
        bp: true,
        bn: true,
        bb: true,
        br: true,
        bq: true,
        bk: true
    });

    function createInitialBoard() {
        return [
            ['br', 'bn', 'bb', 'bq', 'bk', 'bb', 'bn', 'br'],
            ['bp', 'bp', 'bp', 'bp', 'bp', 'bp', 'bp', 'bp'],
            [null, null, null, null, null, null, null, null],
            [null, null, null, null, null, null, null, null],
            [null, null, null, null, null, null, null, null],
            [null, null, null, null, null, null, null, null],
            ['wp', 'wp', 'wp', 'wp', 'wp', 'wp', 'wp', 'wp'],
            ['wr', 'wn', 'wb', 'wq', 'wk', 'wb', 'wn', 'wr']
        ];
    }

    function cloneBoard(board) {
        return Array.isArray(board) ? board.map(function (row) {
            return Array.isArray(row) ? row.slice(0, 8) : Array(8).fill(null);
        }) : createInitialBoard();
    }

    function cloneCastling(castling) {
        var source = castling && typeof castling === 'object' ? castling : {};
        return {
            w: {
                k: !!(source.w && source.w.k),
                q: !!(source.w && source.w.q)
            },
            b: {
                k: !!(source.b && source.b.k),
                q: !!(source.b && source.b.q)
            }
        };
    }

    function normalizePiece(piece) {
        var normalized = String(piece || '').toLowerCase().trim();
        return ALLOWED_PIECES[normalized] ? normalized : null;
    }

    function pieceColor(piece) {
        return piece ? String(piece).charAt(0) : '';
    }

    function pieceType(piece) {
        return piece ? String(piece).charAt(1) : '';
    }

    function opponentColor(color) {
        return color === 'b' ? 'w' : 'b';
    }

    function isInsideBoard(row, col) {
        return row >= 0 && row < 8 && col >= 0 && col < 8;
    }

    function coordsToSquare(row, col) {
        if (!isInsideBoard(row, col)) {
            return '';
        }
        return FILES.charAt(col) + String(8 - row);
    }

    function squareToCoords(square) {
        var normalized = String(square || '').trim().toLowerCase();
        if (!/^[a-h][1-8]$/.test(normalized)) {
            return null;
        }
        return {
            row: 8 - parseInt(normalized.charAt(1), 10),
            col: FILES.indexOf(normalized.charAt(0))
        };
    }

    function getPieceAt(board, row, col) {
        if (!isInsideBoard(row, col)) {
            return null;
        }
        return normalizePiece(board[row] && board[row][col]);
    }

    function createInitialState() {
        var state = {
            version: 1,
            board: createInitialBoard(),
            turn: 'w',
            castling: {
                w: { k: true, q: true },
                b: { k: true, q: true }
            },
            enPassant: null,
            halfmoveClock: 0,
            fullmoveNumber: 1,
            checkColor: null,
            winnerColor: null,
            resultCode: null,
            resultLabel: null,
            lastMove: null,
            repetition: {}
        };
        var key = getPositionKey(state);
        state.repetition[key] = 1;
        return state;
    }

    function normalizeState(rawState) {
        if (!rawState || typeof rawState !== 'object') {
            return createInitialState();
        }

        var board = [];
        var whiteKings = 0;
        var blackKings = 0;
        for (var rowIndex = 0; rowIndex < 8; rowIndex++) {
            var sourceRow = Array.isArray(rawState.board && rawState.board[rowIndex]) ? rawState.board[rowIndex] : [];
            var normalizedRow = [];
            for (var colIndex = 0; colIndex < 8; colIndex++) {
                var piece = normalizePiece(sourceRow[colIndex]);
                if (piece === 'wk') {
                    whiteKings++;
                } else if (piece === 'bk') {
                    blackKings++;
                }
                normalizedRow.push(piece);
            }
            board.push(normalizedRow);
        }

        if (whiteKings !== 1 || blackKings !== 1) {
            return createInitialState();
        }

        if (board[0].some(function (piece) { return piece === 'wp' || piece === 'bp'; })
            || board[7].some(function (piece) { return piece === 'wp' || piece === 'bp'; })) {
            return createInitialState();
        }

        var normalized = {
            version: 1,
            board: board,
            turn: rawState.turn === 'b' ? 'b' : 'w',
            castling: cloneCastling(rawState.castling),
            enPassant: /^[a-h][36]$/.test(String(rawState.enPassant || '').trim()) ? String(rawState.enPassant).trim().toLowerCase() : null,
            halfmoveClock: Math.max(0, Math.min(1000, Number(rawState.halfmoveClock || 0) || 0)),
            fullmoveNumber: Math.max(1, Math.min(2000, parseInt(rawState.fullmoveNumber, 10) || 1)),
            checkColor: rawState.checkColor === 'b' ? 'b' : (rawState.checkColor === 'w' ? 'w' : null),
            winnerColor: rawState.winnerColor === 'b' ? 'b' : (rawState.winnerColor === 'w' ? 'w' : null),
            resultCode: rawState.resultCode ? String(rawState.resultCode) : null,
            resultLabel: rawState.resultLabel ? String(rawState.resultLabel) : null,
            lastMove: normalizeLastMove(rawState.lastMove),
            repetition: {}
        };

        if (rawState.repetition && typeof rawState.repetition === 'object') {
            Object.keys(rawState.repetition).forEach(function (key) {
                var value = Math.max(1, Math.min(10, parseInt(rawState.repetition[key], 10) || 1));
                normalized.repetition[String(key)] = value;
            });
        }

        var positionKey = getPositionKey(normalized);
        if (!normalized.repetition[positionKey]) {
            normalized.repetition[positionKey] = 1;
        }

        return normalized;
    }

    function normalizeLastMove(lastMove) {
        if (!lastMove || typeof lastMove !== 'object') {
            return null;
        }
        var from = String(lastMove.from || '').trim().toLowerCase();
        var to = String(lastMove.to || '').trim().toLowerCase();
        if (!/^[a-h][1-8]$/.test(from) || !/^[a-h][1-8]$/.test(to)) {
            return null;
        }
        var promotion = String(lastMove.promotion || '').trim().toLowerCase();
        if (PROMOTION_PIECES.indexOf(promotion) === -1) {
            promotion = null;
        }
        return {
            from: from,
            to: to,
            piece: normalizePiece(lastMove.piece),
            promotion: promotion,
            notation: String(lastMove.notation || ''),
            uci: String(lastMove.uci || ''),
            playerColor: lastMove.playerColor === 'b' ? 'b' : (lastMove.playerColor === 'w' ? 'w' : null)
        };
    }

    function cloneState(state) {
        return {
            version: 1,
            board: cloneBoard(state.board),
            turn: state.turn === 'b' ? 'b' : 'w',
            castling: cloneCastling(state.castling),
            enPassant: state.enPassant ? String(state.enPassant) : null,
            halfmoveClock: Math.max(0, Number(state.halfmoveClock || 0) || 0),
            fullmoveNumber: Math.max(1, parseInt(state.fullmoveNumber, 10) || 1),
            checkColor: state.checkColor === 'b' ? 'b' : (state.checkColor === 'w' ? 'w' : null),
            winnerColor: state.winnerColor === 'b' ? 'b' : (state.winnerColor === 'w' ? 'w' : null),
            resultCode: state.resultCode ? String(state.resultCode) : null,
            resultLabel: state.resultLabel ? String(state.resultLabel) : null,
            lastMove: normalizeLastMove(state.lastMove),
            repetition: Object.assign({}, state.repetition || {})
        };
    }

    function getPositionKey(state) {
        var rows = [];
        for (var rowIndex = 0; rowIndex < 8; rowIndex++) {
            var row = state.board[rowIndex];
            var buffer = '';
            var emptyCount = 0;
            for (var colIndex = 0; colIndex < 8; colIndex++) {
                var piece = normalizePiece(row[colIndex]);
                if (!piece) {
                    emptyCount++;
                    continue;
                }
                if (emptyCount > 0) {
                    buffer += String(emptyCount);
                    emptyCount = 0;
                }
                buffer += piece;
            }
            if (emptyCount > 0) {
                buffer += String(emptyCount);
            }
            rows.push(buffer || '8');
        }

        var castling = '';
        if (state.castling.w.k) castling += 'K';
        if (state.castling.w.q) castling += 'Q';
        if (state.castling.b.k) castling += 'k';
        if (state.castling.b.q) castling += 'q';
        if (!castling) {
            castling = '-';
        }

        return [
            rows.join('/'),
            state.turn === 'b' ? 'b' : 'w',
            castling,
            state.enPassant || '-'
        ].join('|');
    }

    function findKing(state, color) {
        for (var rowIndex = 0; rowIndex < 8; rowIndex++) {
            for (var colIndex = 0; colIndex < 8; colIndex++) {
                if (state.board[rowIndex][colIndex] === color + 'k') {
                    return {
                        row: rowIndex,
                        col: colIndex,
                        square: coordsToSquare(rowIndex, colIndex)
                    };
                }
            }
        }
        return null;
    }

    function isSquareAttacked(state, row, col, byColor) {
        var pawnSourceRow = byColor === 'w' ? row + 1 : row - 1;
        if (isInsideBoard(pawnSourceRow, col - 1) && getPieceAt(state.board, pawnSourceRow, col - 1) === byColor + 'p') {
            return true;
        }
        if (isInsideBoard(pawnSourceRow, col + 1) && getPieceAt(state.board, pawnSourceRow, col + 1) === byColor + 'p') {
            return true;
        }

        var knightOffsets = [
            [-2, -1], [-2, 1],
            [-1, -2], [-1, 2],
            [1, -2], [1, 2],
            [2, -1], [2, 1]
        ];
        for (var knightIndex = 0; knightIndex < knightOffsets.length; knightIndex++) {
            var knightRow = row + knightOffsets[knightIndex][0];
            var knightCol = col + knightOffsets[knightIndex][1];
            if (isInsideBoard(knightRow, knightCol) && getPieceAt(state.board, knightRow, knightCol) === byColor + 'n') {
                return true;
            }
        }

        var diagonalDirections = [[-1, -1], [-1, 1], [1, -1], [1, 1]];
        for (var diagonalIndex = 0; diagonalIndex < diagonalDirections.length; diagonalIndex++) {
            var diagonalRow = row + diagonalDirections[diagonalIndex][0];
            var diagonalCol = col + diagonalDirections[diagonalIndex][1];
            while (isInsideBoard(diagonalRow, diagonalCol)) {
                var diagonalPiece = getPieceAt(state.board, diagonalRow, diagonalCol);
                if (diagonalPiece) {
                    if (pieceColor(diagonalPiece) === byColor) {
                        var diagonalType = pieceType(diagonalPiece);
                        if (diagonalType === 'b' || diagonalType === 'q') {
                            return true;
                        }
                    }
                    break;
                }
                diagonalRow += diagonalDirections[diagonalIndex][0];
                diagonalCol += diagonalDirections[diagonalIndex][1];
            }
        }

        var straightDirections = [[-1, 0], [1, 0], [0, -1], [0, 1]];
        for (var straightIndex = 0; straightIndex < straightDirections.length; straightIndex++) {
            var straightRow = row + straightDirections[straightIndex][0];
            var straightCol = col + straightDirections[straightIndex][1];
            while (isInsideBoard(straightRow, straightCol)) {
                var straightPiece = getPieceAt(state.board, straightRow, straightCol);
                if (straightPiece) {
                    if (pieceColor(straightPiece) === byColor) {
                        var straightType = pieceType(straightPiece);
                        if (straightType === 'r' || straightType === 'q') {
                            return true;
                        }
                    }
                    break;
                }
                straightRow += straightDirections[straightIndex][0];
                straightCol += straightDirections[straightIndex][1];
            }
        }

        for (var kingRowOffset = -1; kingRowOffset <= 1; kingRowOffset++) {
            for (var kingColOffset = -1; kingColOffset <= 1; kingColOffset++) {
                if (kingRowOffset === 0 && kingColOffset === 0) {
                    continue;
                }
                var kingRow = row + kingRowOffset;
                var kingCol = col + kingColOffset;
                if (isInsideBoard(kingRow, kingCol) && getPieceAt(state.board, kingRow, kingCol) === byColor + 'k') {
                    return true;
                }
            }
        }

        return false;
    }

    function isKingInCheck(state, color) {
        var king = findKing(state, color);
        if (!king) {
            return true;
        }
        return isSquareAttacked(state, king.row, king.col, opponentColor(color));
    }

    function createMove(fromRow, fromCol, toRow, toCol, extra) {
        return Object.assign({
            from: coordsToSquare(fromRow, fromCol),
            to: coordsToSquare(toRow, toCol),
            piece: null,
            capture: null,
            promotion: null,
            castle: null,
            enPassantCaptureSquare: null
        }, extra || {});
    }

    function pushPromotionMoves(moves, fromRow, fromCol, toRow, toCol, base) {
        for (var index = 0; index < PROMOTION_PIECES.length; index++) {
            moves.push(createMove(fromRow, fromCol, toRow, toCol, Object.assign({}, base, {
                promotion: PROMOTION_PIECES[index]
            })));
        }
    }

    function generatePseudoMovesForPiece(state, row, col, color) {
        var piece = getPieceAt(state.board, row, col);
        if (!piece || pieceColor(piece) !== color) {
            return [];
        }

        var type = pieceType(piece);
        var moves = [];

        if (type === 'p') {
            var direction = color === 'w' ? -1 : 1;
            var startRow = color === 'w' ? 6 : 1;
            var promotionRow = color === 'w' ? 0 : 7;
            var oneStepRow = row + direction;
            if (isInsideBoard(oneStepRow, col) && !getPieceAt(state.board, oneStepRow, col)) {
                if (oneStepRow === promotionRow) {
                    pushPromotionMoves(moves, row, col, oneStepRow, col, { piece: piece });
                } else {
                    moves.push(createMove(row, col, oneStepRow, col, { piece: piece }));
                }

                var twoStepRow = row + (direction * 2);
                if (row === startRow && !getPieceAt(state.board, twoStepRow, col)) {
                    moves.push(createMove(row, col, twoStepRow, col, { piece: piece }));
                }
            }

            [-1, 1].forEach(function (colOffset) {
                var captureRow = row + direction;
                var captureCol = col + colOffset;
                if (!isInsideBoard(captureRow, captureCol)) {
                    return;
                }

                var targetPiece = getPieceAt(state.board, captureRow, captureCol);
                if (targetPiece && pieceColor(targetPiece) === opponentColor(color)) {
                    if (captureRow === promotionRow) {
                        pushPromotionMoves(moves, row, col, captureRow, captureCol, {
                            piece: piece,
                            capture: targetPiece
                        });
                    } else {
                        moves.push(createMove(row, col, captureRow, captureCol, {
                            piece: piece,
                            capture: targetPiece
                        }));
                    }
                    return;
                }

                if (state.enPassant && state.enPassant === coordsToSquare(captureRow, captureCol)) {
                    moves.push(createMove(row, col, captureRow, captureCol, {
                        piece: piece,
                        capture: opponentColor(color) + 'p',
                        enPassantCaptureSquare: coordsToSquare(row, captureCol)
                    }));
                }
            });

            return moves;
        }

        if (type === 'n') {
            [
                [-2, -1], [-2, 1],
                [-1, -2], [-1, 2],
                [1, -2], [1, 2],
                [2, -1], [2, 1]
            ].forEach(function (offset) {
                var nextRow = row + offset[0];
                var nextCol = col + offset[1];
                if (!isInsideBoard(nextRow, nextCol)) {
                    return;
                }
                var target = getPieceAt(state.board, nextRow, nextCol);
                if (!target || pieceColor(target) !== color) {
                    moves.push(createMove(row, col, nextRow, nextCol, {
                        piece: piece,
                        capture: target || null
                    }));
                }
            });
            return moves;
        }

        if (type === 'b' || type === 'r' || type === 'q') {
            var directions = [];
            if (type === 'b' || type === 'q') {
                directions = directions.concat([[-1, -1], [-1, 1], [1, -1], [1, 1]]);
            }
            if (type === 'r' || type === 'q') {
                directions = directions.concat([[-1, 0], [1, 0], [0, -1], [0, 1]]);
            }
            directions.forEach(function (directionPair) {
                var nextRow = row + directionPair[0];
                var nextCol = col + directionPair[1];
                while (isInsideBoard(nextRow, nextCol)) {
                    var targetPiece = getPieceAt(state.board, nextRow, nextCol);
                    if (!targetPiece) {
                        moves.push(createMove(row, col, nextRow, nextCol, { piece: piece }));
                    } else {
                        if (pieceColor(targetPiece) !== color) {
                            moves.push(createMove(row, col, nextRow, nextCol, {
                                piece: piece,
                                capture: targetPiece
                            }));
                        }
                        break;
                    }
                    nextRow += directionPair[0];
                    nextCol += directionPair[1];
                }
            });
            return moves;
        }

        if (type === 'k') {
            for (var rowOffset = -1; rowOffset <= 1; rowOffset++) {
                for (var colOffset = -1; colOffset <= 1; colOffset++) {
                    if (rowOffset === 0 && colOffset === 0) {
                        continue;
                    }
                    var targetRow = row + rowOffset;
                    var targetCol = col + colOffset;
                    if (!isInsideBoard(targetRow, targetCol)) {
                        continue;
                    }
                    var targetPiece = getPieceAt(state.board, targetRow, targetCol);
                    if (!targetPiece || pieceColor(targetPiece) !== color) {
                        moves.push(createMove(row, col, targetRow, targetCol, {
                            piece: piece,
                            capture: targetPiece || null
                        }));
                    }
                }
            }

            var homeRow = color === 'w' ? 7 : 0;
            var kingHomeSquare = coordsToSquare(homeRow, 4);
            if (coordsToSquare(row, col) === kingHomeSquare && !isKingInCheck(state, color)) {
                if (state.castling[color].k
                    && !getPieceAt(state.board, homeRow, 5)
                    && !getPieceAt(state.board, homeRow, 6)
                    && getPieceAt(state.board, homeRow, 7) === color + 'r'
                    && !isSquareAttacked(state, homeRow, 5, opponentColor(color))
                    && !isSquareAttacked(state, homeRow, 6, opponentColor(color))) {
                    moves.push(createMove(row, col, homeRow, 6, {
                        piece: piece,
                        castle: 'k'
                    }));
                }

                if (state.castling[color].q
                    && !getPieceAt(state.board, homeRow, 1)
                    && !getPieceAt(state.board, homeRow, 2)
                    && !getPieceAt(state.board, homeRow, 3)
                    && getPieceAt(state.board, homeRow, 0) === color + 'r'
                    && !isSquareAttacked(state, homeRow, 3, opponentColor(color))
                    && !isSquareAttacked(state, homeRow, 2, opponentColor(color))) {
                    moves.push(createMove(row, col, homeRow, 2, {
                        piece: piece,
                        castle: 'q'
                    }));
                }
            }
        }

        return moves;
    }

    function applyMoveInternal(state, move, options) {
        var settings = Object.assign({
            evaluate: true,
            trackRepetition: true
        }, options || {});

        var next = cloneState(state);
        var fromCoords = squareToCoords(move.from);
        var toCoords = squareToCoords(move.to);
        if (!fromCoords || !toCoords) {
            return next;
        }

        var piece = move.piece || getPieceAt(next.board, fromCoords.row, fromCoords.col);
        var movingColor = pieceColor(piece);
        var movingType = pieceType(piece);
        next.board[fromCoords.row][fromCoords.col] = null;

        if (move.enPassantCaptureSquare) {
            var enPassantCoords = squareToCoords(move.enPassantCaptureSquare);
            if (enPassantCoords) {
                next.board[enPassantCoords.row][enPassantCoords.col] = null;
            }
        }

        if (move.castle === 'k') {
            next.board[toCoords.row][toCoords.col] = movingColor + 'k';
            next.board[toCoords.row][5] = movingColor + 'r';
            next.board[toCoords.row][7] = null;
        } else if (move.castle === 'q') {
            next.board[toCoords.row][toCoords.col] = movingColor + 'k';
            next.board[toCoords.row][3] = movingColor + 'r';
            next.board[toCoords.row][0] = null;
        } else {
            next.board[toCoords.row][toCoords.col] = move.promotion ? movingColor + move.promotion : piece;
        }

        next.castling[movingColor].k = movingType === 'k' ? false : next.castling[movingColor].k;
        next.castling[movingColor].q = movingType === 'k' ? false : next.castling[movingColor].q;

        if (movingType === 'r') {
            if (move.from === (movingColor === 'w' ? 'h1' : 'h8')) {
                next.castling[movingColor].k = false;
            }
            if (move.from === (movingColor === 'w' ? 'a1' : 'a8')) {
                next.castling[movingColor].q = false;
            }
        }

        if (move.capture && pieceType(move.capture) === 'r') {
            var opponent = opponentColor(movingColor);
            if (move.to === (opponent === 'w' ? 'h1' : 'h8')) {
                next.castling[opponent].k = false;
            }
            if (move.to === (opponent === 'w' ? 'a1' : 'a8')) {
                next.castling[opponent].q = false;
            }
        }

        next.enPassant = null;
        if (movingType === 'p' && Math.abs(fromCoords.row - toCoords.row) === 2) {
            next.enPassant = coordsToSquare((fromCoords.row + toCoords.row) / 2, fromCoords.col);
        }

        next.halfmoveClock = (movingType === 'p' || move.capture) ? 0 : (next.halfmoveClock + 1);
        next.fullmoveNumber = state.turn === 'b' ? (next.fullmoveNumber + 1) : next.fullmoveNumber;
        next.turn = opponentColor(state.turn);
        next.checkColor = null;
        next.winnerColor = null;
        next.resultCode = null;
        next.resultLabel = null;

        if (settings.trackRepetition) {
            var positionKey = getPositionKey(next);
            next.repetition[positionKey] = Math.max(1, parseInt(next.repetition[positionKey], 10) || 0) + 1;
        }

        if (settings.evaluate) {
            var evaluation = evaluatePosition(next);
            next.checkColor = evaluation.checkColor;
            next.winnerColor = evaluation.winnerColor;
            next.resultCode = evaluation.resultCode;
            next.resultLabel = evaluation.resultLabel;
        }

        var lastMove = {
            from: move.from,
            to: move.to,
            piece: piece,
            capture: move.capture || null,
            promotion: move.promotion || null,
            castle: move.castle || null,
            uci: buildMoveUci(move),
            playerColor: movingColor
        };
        lastMove.notation = buildMoveNotation(lastMove, next);
        next.lastMove = lastMove;

        return next;
    }

    function buildMoveUci(move) {
        return String(move.from || '') + String(move.to || '') + (move.promotion || '');
    }

    function buildMoveNotation(move, nextState) {
        if (move.castle === 'k') {
            return nextState.resultCode === 'checkmate' ? 'O-O#' : (nextState.checkColor === nextState.turn ? 'O-O+' : 'O-O');
        }
        if (move.castle === 'q') {
            return nextState.resultCode === 'checkmate' ? 'O-O-O#' : (nextState.checkColor === nextState.turn ? 'O-O-O+' : 'O-O-O');
        }

        var type = pieceType(move.piece);
        var notation = PIECE_NAMES[type] || '';
        if (type === 'p' && move.capture) {
            notation += String(move.from || '').charAt(0);
        }
        if (move.capture) {
            notation += 'x';
        }
        notation += move.to;
        if (move.promotion) {
            notation += '=' + (PIECE_NAMES[move.promotion] || 'Q');
        }
        if (nextState.resultCode === 'checkmate') {
            notation += '#';
        } else if (nextState.checkColor === nextState.turn) {
            notation += '+';
        }
        return notation;
    }

    function getLegalMovesForColor(state, color) {
        var normalizedState = normalizeState(state);
        var legalMoves = [];

        for (var rowIndex = 0; rowIndex < 8; rowIndex++) {
            for (var colIndex = 0; colIndex < 8; colIndex++) {
                var piece = getPieceAt(normalizedState.board, rowIndex, colIndex);
                if (!piece || pieceColor(piece) !== color) {
                    continue;
                }
                var pseudoMoves = generatePseudoMovesForPiece(normalizedState, rowIndex, colIndex, color);
                pseudoMoves.forEach(function (pseudoMove) {
                    var candidateState = applyMoveInternal(normalizedState, pseudoMove, {
                        evaluate: false,
                        trackRepetition: false
                    });
                    if (!isKingInCheck(candidateState, color)) {
                        legalMoves.push(pseudoMove);
                    }
                });
            }
        }

        return legalMoves;
    }

    function getLegalMovesForSquare(state, square) {
        var normalizedState = normalizeState(state);
        var normalizedSquare = String(square || '').trim().toLowerCase();
        if (!/^[a-h][1-8]$/.test(normalizedSquare)) {
            return [];
        }
        return getLegalMovesForColor(normalizedState, normalizedState.turn).filter(function (move) {
            return move.from === normalizedSquare;
        });
    }

    function evaluatePosition(state) {
        var colorToMove = state.turn === 'b' ? 'b' : 'w';
        var checkColor = isKingInCheck(state, colorToMove) ? colorToMove : null;
        var legalMoves = getLegalMovesForColor(state, colorToMove);
        var currentKey = getPositionKey(state);
        var repetitionCount = Math.max(1, parseInt(state.repetition[currentKey], 10) || 1);

        if (!legalMoves.length) {
            if (checkColor) {
                return {
                    checkColor: colorToMove,
                    winnerColor: opponentColor(colorToMove),
                    resultCode: 'checkmate',
                    resultLabel: 'Checkmate'
                };
            }
            return {
                checkColor: null,
                winnerColor: null,
                resultCode: 'stalemate',
                resultLabel: 'Stalemate'
            };
        }

        if (isInsufficientMaterial(state)) {
            return {
                checkColor: checkColor,
                winnerColor: null,
                resultCode: 'draw_insufficient_material',
                resultLabel: 'Draw by insufficient material'
            };
        }

        if (state.halfmoveClock >= 100) {
            return {
                checkColor: checkColor,
                winnerColor: null,
                resultCode: 'draw_fifty_move',
                resultLabel: 'Draw by fifty-move rule'
            };
        }

        if (repetitionCount >= 3) {
            return {
                checkColor: checkColor,
                winnerColor: null,
                resultCode: 'draw_threefold',
                resultLabel: 'Draw by repetition'
            };
        }

        return {
            checkColor: checkColor,
            winnerColor: null,
            resultCode: null,
            resultLabel: null
        };
    }

    function isInsufficientMaterial(state) {
        var pieces = [];
        for (var rowIndex = 0; rowIndex < 8; rowIndex++) {
            for (var colIndex = 0; colIndex < 8; colIndex++) {
                var piece = getPieceAt(state.board, rowIndex, colIndex);
                if (!piece || pieceType(piece) === 'k') {
                    continue;
                }
                pieces.push({
                    piece: piece,
                    row: rowIndex,
                    col: colIndex
                });
            }
        }

        if (pieces.length === 0) {
            return true;
        }

        if (pieces.length === 1) {
            var loneType = pieceType(pieces[0].piece);
            return loneType === 'b' || loneType === 'n';
        }

        if (pieces.length === 2) {
            var bothBishops = pieceType(pieces[0].piece) === 'b' && pieceType(pieces[1].piece) === 'b';
            if (bothBishops && pieceColor(pieces[0].piece) !== pieceColor(pieces[1].piece)) {
                var squareParityA = (pieces[0].row + pieces[0].col) % 2;
                var squareParityB = (pieces[1].row + pieces[1].col) % 2;
                return squareParityA === squareParityB;
            }
        }

        return false;
    }

    function attemptMove(state, from, to, promotion) {
        var normalizedState = normalizeState(state);
        var normalizedFrom = String(from || '').trim().toLowerCase();
        var normalizedTo = String(to || '').trim().toLowerCase();
        var normalizedPromotion = String(promotion || '').trim().toLowerCase();
        if (!/^[a-h][1-8]$/.test(normalizedFrom) || !/^[a-h][1-8]$/.test(normalizedTo)) {
            return {
                ok: false,
                reason: 'Invalid chess square.'
            };
        }

        var legalMoves = getLegalMovesForSquare(normalizedState, normalizedFrom).filter(function (move) {
            return move.to === normalizedTo;
        });
        if (!legalMoves.length) {
            return {
                ok: false,
                reason: 'That move is not legal.'
            };
        }

        if (legalMoves.some(function (move) { return !!move.promotion; })) {
            if (PROMOTION_PIECES.indexOf(normalizedPromotion) === -1) {
                return {
                    ok: false,
                    needsPromotion: true,
                    options: PROMOTION_PIECES.slice()
                };
            }
            legalMoves = legalMoves.filter(function (move) {
                return move.promotion === normalizedPromotion;
            });
            if (!legalMoves.length) {
                return {
                    ok: false,
                    reason: 'That promotion choice is not legal.'
                };
            }
        }

        var chosenMove = legalMoves[0];
        var nextState = applyMoveInternal(normalizedState, chosenMove, {
            evaluate: true,
            trackRepetition: true
        });

        return {
            ok: true,
            state: nextState,
            move: nextState.lastMove
        };
    }

    function getPieceGlyph(piece) {
        if (!piece) {
            return '';
        }

        var color = pieceColor(piece);
        var type = pieceType(piece);
        var filledGlyphs = {
            p: '\u265F',
            n: '\u265E',
            b: '\u265D',
            r: '\u265C',
            q: '\u265B',
            k: '\u265A'
        };
        if (color === 'w' && filledGlyphs[type]) {
            return filledGlyphs[type];
        }

        return PIECE_GLYPHS[piece] || '';
    }

    function getColorLabel(color) {
        return color === 'b' ? 'Black' : 'White';
    }

    global.QuillTalkChess = {
        createInitialState: createInitialState,
        normalizeState: normalizeState,
        getLegalMovesForSquare: getLegalMovesForSquare,
        getLegalMovesForColor: getLegalMovesForColor,
        attemptMove: attemptMove,
        getPositionKey: getPositionKey,
        getPieceGlyph: getPieceGlyph,
        getColorLabel: getColorLabel,
        coordsToSquare: coordsToSquare,
        squareToCoords: squareToCoords
    };
})(window);
